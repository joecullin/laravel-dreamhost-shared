<?php

// The code and config that we're deploying:

$repo_url = 'ssh://git.my_company.com/ ... /my_site';
$repo_url_config = 'ssh://git.my_company.com/ ... /my_site_config';

// Target dreamhost shared server:

$servers = array('www.my_site.com');
$ssh_user = 'my_username@';
$target_root = '/home/my_username/my_site.com';
$sync_test_dir = '/home/my_username';

// Local cache dir:
$quickbuild_cache = '/home/vagrant/build_cache_my_site';


