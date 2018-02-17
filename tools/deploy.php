#!/usr/bin/php
<?php

// Deploy to production

error_reporting( error_reporting() & ~E_NOTICE );
$tools_dir = dirname(__FILE__);
require_once("{$tools_dir}/tools.php");
require_once("{$tools_dir}/deploy_config.php");

$ssh_cmd = 'ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o LogLevel=ERROR';
$rsync_cmd = implode(' ', array(
                    'rsync',
                    '-r',  // recursive
                    '-t',  // preserve modification times (makes the transfer faster)
                    '-z',  // compress
                    '-e "' . $ssh_cmd . '"', // ssh options
                    '--chmod=Du=rwx,Dg=rx,Do=rx,Fu=rw,Fg=r,Fo=r', // Dirs=755 Files=644
                    '--partial',   // clean up partial files if interrupted
                    '--progress',  // show progress info
                    "--exclude-from {$tools_dir}/deploy_exclude.txt", // exclude some files
                    '--delete-delay',  // remove any old remote files, after sync completes
                ));

$options = array();
$options = getopt("", array(
        "target::",        // --target=prod - "stage" (default) or "prod"
        "branch::",        // --git branch to pull (origin/<branch> at HEAD)
        "dryrun::",        // --dryrun=1 - don't actually publish anything.
        "verbose::",       // --verbose=1 - verbose output. 
        "sshdebug::",      // --sshdebug=1 - verbose ssh debug and then exit.
        "quick::",         // --quick=1 - re-use previous composer,npm builds.
));
$target = $options['target']=='prod' ? 'prod' : 'stage';
$target = 'prod'; // We don't have stage yet.

$branch_name = $options['branch'];
$dryrun = $options['dryrun']==1 ? true : false;
$verbose = $options['verbose']==1 ? true : false;
$ssh_debug = $options['sshdebug']==1 ? true : false;
$quick_build = $options['quick']==1 ? true : false;

verify_ssh_permissions($ssh_debug);
$branch = verify_and_resolve_branch($branch_name);
$build_dir = create_build_dir($branch);
verify_sync_permissions();

echo "\n#############################################################\n";
echo "\nHere's the plan:\n";
echo "\tTarget: {$target}\n";
echo "\tTarget dir: {$target_root}\n";
echo "\tServers to update:\n\t\t" . implode("\n\t\t", $servers) . "\n";
echo "\tSource: $branch\n";
echo "\tBuild dir: $build_dir\n";
echo "\tDry run: " . ($dryrun ? 'yes' : 'no') . "\n";
echo "\tQuick build: " . ($quick_build ? 'yes' : 'no') . "\n";

prompt_confirm_or_quit("Ok to proceed?");

echo "Cloning repo.\n";
run_cmd("git clone -b {$branch} --single-branch {$repo_url} {$build_dir}");
$site_source_dir = "{$build_dir}";

if (!is_dir("{$site_source_dir}/app")) { error_exit("Something is wrong. No app dir in $site_source_dir."); }
list($commit_rev) = run_cmd("git -C {$build_dir} rev-parse HEAD");
echo "Hash: $commit_rev\n";

echo "Overlaying {$target} config.\n";
run_cmd("git archive --remote={$repo_url_config} HEAD:{$target} .env | tar -x -O > {$build_dir}/.env");

// check or create quickbuild cache dir
if ($quickbuild_cache != '' && !is_dir($quickbuild_cache)){
    echo "Creating quickbuild_cache dir: $quickbuild_cache\n";
    mkdir($quickbuild_cache);
}
$quick_build_composer = $quick_build_npm = $quick_build;

// See if we can skip the composer step.
if ($quick_build_composer){
    $cached_vendor_dir = "{$quickbuild_cache}/vendor";
    if (is_dir($cached_vendor_dir)
        && md5_file("{$quickbuild_cache}/composer.json") == md5_file("{$build_dir}/composer.json")
        && md5_file("{$quickbuild_cache}/composer.lock") == md5_file("{$build_dir}/composer.lock")
       ){
         echo "Re-using vendor dir from previous build.\n";
         run_cmd("cp -R {$quickbuild_cache}/vendor $build_dir"); 
    }
    else{
        echo "The composer settings look different.\n";
        $quick_build_composer = false;
    }
}
if (!$quick_build_composer){
	echo "Running composer to install packages.\n";
	$composer_no_dev = $target=='dev' ? '' : '--no-dev';
	run_cmd("composer install {$composer_no_dev} --working-dir={$build_dir}");
    if ($quickbuild_cache){
        // cache for next time.
        run_cmd("cp -R {$build_dir}/vendor {$build_dir}/composer.lock {$build_dir}/composer.json {$quickbuild_cache}");
    }
}

// See if we can skip the node install step.
if ($quick_build){
    $cached_npm_dir = "{$quickbuild_cache}/node_modules";
    if (is_dir($cached_npm_dir)
        && md5_file("{$quickbuild_cache}/package.json") == md5_file("{$build_dir}/package.json")
       ){
         echo "Re-using node_modules dir from previous build.\n";
         run_cmd("cp -R {$quickbuild_cache}/node_modules $build_dir"); 
    }
    else{
        echo "The npm settings look different.\n";
        $quick_build = false;
    }
}

if (!$quick_build){
	run_cmd("npm install --prefix {$build_dir}");
    if ($quickbuild_cache){
        // cache for next time.
        run_cmd("cp -R {$build_dir}/node_modules {$build_dir}/package.json {$quickbuild_cache}");
    }
}

echo "Building css & js.\n";
run_cmd("npm run --prefix {$build_dir} prod");

prompt_confirm_or_quit("All built on local server. Ready to sync?");

echo "Syncing files.\n";
$cmds = array(
    "$rsync_cmd {$site_source_dir}/ {$ssh_user}__SERVER_NAME__:/{$target_root}",
);

foreach ($servers as $server){
    if ($verbose) { echo "\n\n----------------------------------------\n\n"; }
    echo "Updating server $server ...\n";
    foreach ($cmds as $cmd){
        $cmd = preg_replace('/__SERVER_NAME__/', $server, $cmd);
        if ($dryrun){
            echo "DRYRUN: $cmd\n";
        }
        else{
            run_cmd($cmd);
        }
    }
}

cleanup_before_exit();
echo "\nDone.\n";
exit;


////////////////////////////////////////////////////////////////////////

function verify_and_resolve_branch($branch_name){
    global $repo_url;
    $branch_name = preg_replace('!^origin/!', '', trim($branch_name));
    if ($branch_name == ''){
        // I'm only using master for now.
        return 'master';
        // error_exit("--branch arg is required.");
    }

    $output = run_cmd("git ls-remote '{$repo_url}' 'refs/heads/{$branch_name}'");
    if (count($output) != 1){
        print_r($output);
        error_exit("Specified branch ({$branch_name}) not found or not unique. See above list of matching branches.");
    }
    return $branch_name;
}

function create_build_dir($branch){
    $build_dir = "/tmp/deploy_" . implode('_',
                    array(
                        getenv('USER'),
                        preg_replace('/[^a-zA-Z0-9.]/', '', $branch),
                        time(),
                    ));
    // Might need to refine this if we schedule automated builds, but it's unique enough for now.
    if (is_dir($build_dir)) { error_exit("Build dir '{$build_dir}' already exists."); }
    mkdir($build_dir);
    if (!is_dir($build_dir)){
        error_exit("Failed to create build directory '$build_dir'.");
    }
    return $build_dir;
}
function cleanup_before_exit(){
    global $build_dir;
    if ($build_dir=='' || !is_dir($build_dir)){ return; }
    run_cmd(array('cmd' => "rm -Rf {$build_dir}", 'fatal_errors' => 0));
}

function prompt_confirm_or_quit($msg){
    if ($msg == '') { $msg = "Somebody put a blank message in this prompt."; }
    if (!prompt_confirm($msg)){
        echo "Ok bye.\n";
        cleanup_before_exit();
        exit;
    }
    return true;
}

function verify_ssh_permissions($debug){
    global $ssh_user, $rsync_cmd, $ssh_cmd, $servers, $target_root;

    $debug_flags = $debug ? '-v' : '';

    $all_ok = true;
    foreach ($servers as $server){
        echo "Verifying ssh access to {$server}.\n";
        $output = run_cmd(array(
                'cmd' => "{$ssh_cmd} {$debug_flags} {$ssh_user}{$server} 'ls {$target_root}/'",
                'fatal_errors' => 0,
                ));
        if (count($output)==0){
            echo "FAILED\n";
            $all_ok = false;
        }
    }

    if ($debug){
        echo "\n\n";
        if ($all_ok){
            echo "All looks ok.\n";
        }
        else{   // Probably won't get here, error_exit called above.
            echo "No good.\n";
        }

        echo "Finished ssh debug. Exiting.\n";
        cleanup_before_exit();
        exit;
    }



    if (!$all_ok){
        echo <<<END_SSH_INSTRUCTIONS

    This script requires ssh access as a certain user, and checks for a specific known file.
    Please investigate the above error.

END_SSH_INSTRUCTIONS;
        error_exit("Cannot continue.");
    }
}


function verify_sync_permissions(){
    global $servers, $build_dir, $ssh_user, $rsync_cmd, $ssh_cmd, $sync_test_dir;
    $server = $servers[0];
    echo "Verifying access to {$server}.\n";

    $test_file = "{$build_dir}/test_sync.txt";
    $test_fh = fopen($test_file, 'w') or error_exit("Cannot open file '{$test_file}'.");
    fwrite($test_fh, "test sync to prod/stage.\n");
    fclose($test_fh);

    // Try rsync & ssh - create and remove a file.
    run_cmd("{$rsync_cmd} {$test_file} {$ssh_user}{$server}:{$sync_test_dir}");
    run_cmd("{$ssh_cmd} {$ssh_user}{$server} 'rm -f {$sync_test_dir}/test_sync.txt'");

    unlink ($test_file);
}


