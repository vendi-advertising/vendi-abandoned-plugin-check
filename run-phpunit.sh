#!/bin/bash

##see: http://stackoverflow.com/questions/192249/how-do-i-parse-command-line-arguments-in-bash
# Use -gt 1 to consume two arguments per pass in the loop
# Use -gt 0 to consume one or more arguments per pass in the loop
while [[ $# -gt 0 ]]
do
    key="$1"
    echo "$key";

        case $key in

            -g|--group)
                GROUP="$2"
                shift # past argument
            ;;

            --update-composer)
                UPDATE=true
            ;;

            *)
                    # unknown option
            ;;
        esac

    shift # past argument or value
done

if [ "$UPDATE" = true ]; then
    composer update
    composer install --dev
fi

if [ -z "$GROUP" ]; then
    ./vendor/bin/phpunit --coverage-html ./tests/logs/coverage/
else
    ./vendor/bin/phpunit --coverage-html ./tests/logs/coverage/ --group $GROUP
fi


#phpunit --coverage-html ./tests/logs/coverage/

