## Checklist
- [ ] I ran `find . -name '*.php' -print0 | xargs -0 -n1 -P4 php -l`
- [ ] I didn’t introduce calls to functions not defined in this repo (esp. in `app/ui.php`)
- [ ] If I added/changed a helper, I updated `app/ui.php` (and kept it backward compatible)
- [ ] I tested the changed routes locally
