<?php

/*
Plugin Name:  Unlocker Landings
Plugin URI:   https://unlocker.io/
Description:  Serves the "conciergeries de montagne" campaign landings (split de paiement, délégation Carte G, formulaire démarrer) pixel-perfect from a design mockup, on dedicated page templates.
Version:      1.0.0
Author:       Unlocker
Author URI:   https://unlocker.io/
License:      Proprietary
*/

// This is the only file in this feature with a plugin header: the rest lives
// in unlocker-landings/, required below, so bedrock-autoloader never treats
// it as a second must-use plugin.

require_once __DIR__ . '/unlocker-landings/inc/constants.php';
require_once __DIR__ . '/unlocker-landings/inc/faq.php';
require_once __DIR__ . '/unlocker-landings/inc/templates.php';
require_once __DIR__ . '/unlocker-landings/inc/assets.php';
require_once __DIR__ . '/unlocker-landings/inc/ads-landings.php';
require_once __DIR__ . '/unlocker-landings/inc/lead.php';
require_once __DIR__ . '/unlocker-landings/inc/seo.php';
