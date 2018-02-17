# laravel-dreamhost-shared
Tools for running Laravel on DreamHost shared hosting

* deploy.php - script for deploying to production, using only rsync.
* deploy_config.php - settings

A simple script for deploying a Laravel project to production. The only production server requirement is ssh/rsync access. The script does the composer/npm/webpack/config work on your local server before syncing files.

I needed this for Laravel on DreamHost shared hosting, because composer exceeds the memory limits on the shared server.

Notes:
* The composer and npm steps take a long time. Use the ```--quick=1``` option. That will try to re-use those files from the previous build.
* I keep my .env files in a separate repo from my code. Adjust as needed to fit your environment.
* In the DreamHost panel there's an option for "Web directory" (i.e. Document Root). Set it to my_site.com/public.
* Some of the flags are remnants from another project. Full support for 'stage' environment and multiple (load-balanced) servers would be easy to restore, but it's not fully usable in this code right now.
* You should use ssh keys for accessing your server, or else you might get a lot of annoying password prompts. https://help.dreamhost.com/hc/en-us/articles/216499537-How-to-configure-passwordless-login-in-Mac-OS-X-and-Linux has instructions.
* Disclaimer: I am still a Laravel novice, and I'm not hosting anything critical or high-traffic yet.

Examples:

```bash
./deploy.php --quick=1
./deploy.php --dryrun=1 --verbose=1
```
