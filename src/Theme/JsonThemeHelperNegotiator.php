<?php
/**
 * Created by PhpStorm.
 * User: lgregory
 * Date: 17/08/2015
 * Time: 14:05
 */
namespace Drupal\json_theme_helper\Theme;

use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\Core\Routing\RouteMatchInterface;

class JsonThemeHelperNegotiator implements ThemeNegotiatorInterface {

    /**
     * {@inheritdoc}
     */
    public function applies(RouteMatchInterface $route_match) {

        if ($route_match->getRouteObject() !== null){    
          $URI = $route_match->getRouteObject()->getPath();
          // Use this theme on a certain route.
          return substr($URI,1,4) == 'view';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function determineActiveTheme(RouteMatchInterface $route_match) {
        // Here you return the actual theme name.
        return 'json';
    }
}
?>