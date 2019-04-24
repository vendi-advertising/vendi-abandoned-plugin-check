<?php

namespace Vendi\Plugin\HealthCheck;

final class Utils
{
    public static function get_js_for_install_card_by_id(string $id)
    {
        return <<<EOT
<script>
    //Grab our random unique ID
    var n = document.getElementById('{$id}');

    //Make sure we have something and loop through each parent
    while( n && n.parentNode )
    {
        //If the parent has the class or tag that we're looking for
        if(
            ( n.parentNode.className && n.parentNode.className.indexOf( 'plugin-card' ) >= 0 )  //WP 4.0 and greater
            ||
            ( n.parentNode.nodeName && n.parentNode.nodeName.toUpperCase() === 'TR' )           //WP 3.9 and less
          )
        {
            //Make it stand out
            n.parentNode.style.backgroundColor = '#f99';
            n.parentNode.style.borderColor = '#f00';

            //We found it, done.
            break;
        }

        //We didn't find anything, walk up one more parent
        n = n.parentNode;
    }
</script>
EOT;
    }
}
