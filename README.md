# EZM Security Settings

WordPress plugin for EZMarketing client sites. Provides login error obscuring (username enumeration protection) and restricted REST API access.

## Updates

This plugin updates from GitHub Releases (zip asset attached to the release):

https://github.com/ezmarketing/ezm-security-settings

No token or `wp-config` changes are required (public repository).

### Shipping a new version

1. Bump the `Version` header in `ezm-security-settings.php` (e.g. `1.2`).
2. Zip the `ezm-security-settings` folder (zip root must be that folder name).
3. On GitHub: create a Release tagged to match (e.g. `v1.2`) and **attach the zip** as a release asset.
4. Optionally upload/update source files in the repo for reference; client updates come from the Release zip.
5. Client sites will see the update under **Plugins** in WordPress admin.
