<?php

/**
 * @file
 * Contains \Drupal\json_theme_helper\Plugin\views\style\SerializerMod.
 */

namespace Drupal\json_theme_helper\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\CacheablePluginInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The style plugin for serialized output formats.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "serializerMod",
 *   title = @Translation("SerializerMod"),
 *   help = @Translation("Serializes views row data using the Serializer component."),
 *   display_types = {"data"}
 * )
 */
class SerializerMod extends StylePluginBase implements CacheablePluginInterface {

  /**
   * Overrides \Drupal\views\Plugin\views\style\StylePluginBase::$usesRowPlugin.
   */
  protected $usesRowPlugin = TRUE;

  /**
   * Overrides Drupal\views\Plugin\views\style\StylePluginBase::$usesFields.
   */
  protected $usesGrouping = FALSE;

  /**
   * The serializer which serializes the views result.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  protected $formats = array();

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('serializer'),
      $container->getParameter('serializer.formats')
    );
  }

  /**
   * Constructs a Plugin object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SerializerInterface $serializer, array $serializer_formats) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->definition = $plugin_definition + $configuration;
    $this->serializer = $serializer;
    $this->formats = $serializer_formats;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['formats'] = array('default' => array());

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['formats'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Accepted request formats'),
      '#description' => $this->t('Request formats that will be allowed in responses. If none are selected all formats will be allowed.'),
      '#options' => array_combine($this->formats, $this->formats),
      '#default_value' => $this->options['formats'],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    $formats = $form_state->getValue(array('style_options', 'formats'));
    $form_state->setValue(array('style_options', 'formats'), array_filter($formats));
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $rows = array();
    // If the Data Entity row plugin is used, this will be an array of entities
    // which will pass through Serializer to one of the registered Normalizers,
    // which will transform it to arrays/scalars. If the Data field row plugin
    // is used, $rows will not contain objects and will pass directly to the
    // Encoder.
    foreach ($this->view->result as $row) {
      $tmpArr = $this->view->rowPlugin->render($row);
      //fix permissions
      $entity = $row->_entity;
      if (array_key_exists('type',$tmpArr)){
        $tmpArr['type'] = $entity->getType();
      }
      //assign to working array
      $rows[] = $tmpArr;
    }
    
    // Get the content type configured in the display or fallback to the
    // default.
    if ((empty($this->view->live_preview))) {
      $content_type = $this->displayHandler->getContentType();
    }
    else {
      $content_type = !empty($this->options['formats']) ? reset($this->options['formats']) : 'json';
    }
    foreach ($rows as $rowIndex => $row){
       foreach($row as $fieldIndex => $field){
       
       if (is_object($rows[$rowIndex][$fieldIndex])){
         $rows[$rowIndex][$fieldIndex] = $rows[$rowIndex][$fieldIndex]->__toString();
       }
       
       if ($fieldIndex == 'field_images'){
        $rows[$rowIndex][$fieldIndex] = "[".$rows[$rowIndex][$fieldIndex]."]";
       }
       
        $decodedData = json_decode($rows[$rowIndex][$fieldIndex],true);
         
        if ($decodedData !== null && is_array($decodedData)){          
          array_walk_recursive($decodedData, function(&$item){
            if (!is_array($item)){
              $item = htmlspecialchars_decode(html_entity_decode(urldecode($item),ENT_QUOTES));              
            }
          }); 
          $rows[$rowIndex][$fieldIndex] = $decodedData;
        }else{          
          $rows[$rowIndex][$fieldIndex] = htmlspecialchars_decode(html_entity_decode(urldecode($rows[$rowIndex][$fieldIndex]),ENT_QUOTES));
        }
       }
    }
    
    return json_encode($rows);
    //return $this->serializer->serialize($rows, $content_type);
  }

  /**
   * Gets a list of all available formats that can be requested.
   *
   * This will return the configured formats, or all formats if none have been
   * selected.
   *
   * @return array
   *   An array of formats.
   */
  public function getFormats() {
    return $this->options['formats'];
  }

  /**
   * {@inheritdoc}
   */
  public function isCacheable() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['request_format'];
  }

}
