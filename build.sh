php -r "require_once 'vendor/autoload.php'; $txt = file_get_contents('readme.txt'); $md = \WPReadme2Markdown\Converter::convert($txt); file_put_contents('README.md', $md);"


./vendor/bin/wp2md convert < readme.txt > README.md

php ~/wordpress-dev-tools/tools/i18n/makepot.php wp-plugin . languages/vendi-abandoned-plugin-check.pot

#cd ../
#rm -f vendi-cache.zip
#zip -r9 vendi-cache.zip vendi-cache/  -x "*/.*" -x vendi-cache/.git* -x "vendi-cache/tests*" -x "vendi-cache/bin*" -x vendi-cache/.travis.yml -x vendi-cache/*.md -x vendi-cache/.editorconfig -x #vendi-cache/.distignore -x vendi-cache/phpunit.xml -x vendi-cache/Gruntfile.js -x vendi-cache/build.sh -x vendi-cache/run-phpunit.sh -x vendi-cache/phpunit.xml.dist -x vendi-cache/package.json
#cd vendi-cache
