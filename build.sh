if [ ! -d bin ]; then
    mkdir bin
fi

if [ ! -f bin/wp2md ]; then
    wget https://github.com/wpreadme2markdown/wp-readme-to-markdown/releases/download/2.0.2/wp2md.phar -O bin/wp2md
    chmod a+x bin/wp2md
fi

./bin/wp2md convert < readme.txt > README.md

php ~/wordpress-dev-tools/tools/i18n/makepot.php wp-plugin . languages/vendi-abandoned-plugin-check.pot

#cd ../
#rm -f vendi-cache.zip
#zip -r9 vendi-cache.zip vendi-cache/  -x "*/.*" -x vendi-cache/.git* -x "vendi-cache/tests*" -x "vendi-cache/bin*" -x vendi-cache/.travis.yml -x vendi-cache/*.md -x vendi-cache/.editorconfig -x #vendi-cache/.distignore -x vendi-cache/phpunit.xml -x vendi-cache/Gruntfile.js -x vendi-cache/build.sh -x vendi-cache/run-phpunit.sh -x vendi-cache/phpunit.xml.dist -x vendi-cache/package.json
#cd vendi-cache
