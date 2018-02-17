<?php

// A few common functions used by scripts in /tools.


function log_verbose($msg){
    global $verbose;
    if (!$verbose) { return; }

    $verbose_prefix = "#        ";
    $msg = preg_replace('/^/m', $verbose_prefix, $msg);
    echo "$msg\n";
}

// Args: either a simple command string, or an array of options.
// Examples:
//  run_cmd("ls /tmp");
//  run_cmd(array('cmd' => "rm -Rf {$build_dir}", 'fatal_errors' => 0));
//
function run_cmd($args){

    if (! is_array($args) ){
        $cmd = $args;
        $args = array(
                'cmd' => $cmd,
                'fatal_errors' => 1,
                'quiet_errors' => 0,
            );
    }

    $cmd = $args['cmd'];
    if (! array_key_exists('fatal_errors', $args)) { $args['fatal_errors'] = 1; }
    $fatal_errors = $args['fatal_errors'];
    if (! array_key_exists('quiet_errors', $args)) { $args['quiet_errors'] = 0; }
    $quiet_errors = $args['quiet_errors'];

    log_verbose($cmd);

    $cmd_output_array = array();
    exec($cmd, $cmd_output_array, $exitcode);
    log_verbose("exitcode: $exitcode");
    $cmd_output = implode("\n\t\t", $cmd_output_array);
    log_verbose("output:\n\t\t" . $cmd_output);
    if ($exitcode != 0){
        if ($fatal_errors){
            error_exit("Non-zero exitcode!!");
        }
        elseif($quiet_errors){
            // No output
        }
        else{
            echo "Non-zero exitcode (non-fatal)\n";
        }
    }
    return $cmd_output_array;
}

function error_exit($msg)
{
    echo "\nERROR! Exiting.\n";
    echo $msg . "\n";
    cleanup_before_exit();
    exit(1);
}

function prompt_confirm($message=''){
    $confirmed = 0;
    echo "\n" . $message . "\n\tType 'y' to continue: ";
    $handle = fopen ("php://stdin","r");
    $line = fgets($handle);
    if(trim($line) == 'y'){
        $confirmed = 1;
    }
    fclose($handle);
    return $confirmed;
}

// This is not very nuanced, but at least it can detect someone who has no sudo privileges.
function verify_sudo_access(){
    echo "Verifying sudo access.\n";
    run_cmd("sudo -n uptime 2>/dev/null 1>/dev/null");
}

