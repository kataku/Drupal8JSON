# Drupal8JSON

This code is a custom hack to let views output json of complex objects such as paragraphs. It's got renderers for views and uses a theme to encode a lot of content. It's far from generic but is in use and could serve as a base if not full implementation for others needs

Move the json theme from the theme folder, configure JsonThemeHelperNegotiator.php to direct your urls to use that theme selectively.