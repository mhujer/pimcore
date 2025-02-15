# Appearance & Branding

In the appearance & branding settings (*Settings* > *Appearance & Branding*) system-wide settings for Pimcore Admin Interface can be made. Changes should
be made with care and only by developers.
These settings are saved in `var/config/admin_system_settings/admin_system_settings.yaml` or in the settings store based on your configuration.

## Colors
Settings regarding colors of Pimcore admin interface like login screen color, Admin interface color, Admin interface background etc.

## Custom Logo
Settings allowing to customize the logo in Pimcore admin interface.

## Custom Login Background Image
Settings allowing to customize the background image in Pimcore login screen.

## Assets
Settings for assets like disabling tree preview or hiding edit image tab in the admin interface.

## Access admin config in PHP Controller
You can access the admin settings configuration like in the following example:

```php 
<?php

namespace App\Controller;

use Pimcore\Bundle\AdminBundle\System\AdminConfig;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends FrontendController
{
    public function defaultAction(Request $request, AdminConfig $config): Response
    {
        // use type-hinting to inject the config service
        $config = $config->getAdminSystemSettingsConfig();
        $bar = $config['branding']['color_login_screen'];
    }
}
```
