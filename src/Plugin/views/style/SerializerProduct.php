<?php

/**
 * @file
 * Contains \Drupal\json_theme_helper\Plugin\views\style\SerializerProduct.
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
 *   id = "SerializerProduct",
 *   title = @Translation("SerializerProduct"),
 *   help = @Translation("Serializes views row data using the Serializer component."),
 *   display_types = {"data"}
 * )
 */
class SerializerProduct extends StylePluginBase implements CacheablePluginInterface {

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

    $fields = array();
    foreach ($this->view->result as $row) {
      $tmpArrRender = $this->view->rowPlugin->render($row);
      
      /**
       * @var $entity \Drupal\node\Entity\Node
       * @var $fieldData \Drupal\Core\Field\FieldItemList|\Drupal\Core\Field\EntityReferenceFieldItemList
       * @var $imageData \Drupal\image\Plugin\Field\FieldType\ImageItem|\Drupal\Core\Entity\Plugin\DataType\EntityReference
       * @var $imageEntity \Drupal\Core\Entity\Plugin\DataType\EntityReference|\Drupal\file\Entity\File
       */
      $entity = $row->_entity;
      
      foreach($entity->getFields() as $fieldID => $fieldData){
        $val = null;

        // if of type image
        if($fieldData->getFieldDefinition()->getType() === "image"){
          $val = array();
          foreach($fieldData as $imageData){
            $imageEntity = $imageData->getProperties(true)['entity']->getValue();
            $val[] = array(
              "alt"     =>  $imageData->getValue()['alt'],
              "file"    =>  $imageEntity->getFilename(),
              "type"    =>  $imageEntity->getMimeType(),
              "size"    =>  $imageEntity->getSize()
            );
          }
        }

        // if of type entity_reference
        if($fieldData->getFieldDefinition()->getType() === "entity_reference"){
          if(array_key_exists($fieldID,$tmpArrRender)) {
            $val = $tmpArrRender[$fieldID];
            $valDecode = json_decode($val, TRUE);
            if ($valDecode !== NULL) {
              $val = $valDecode;
            }
          }
        }

        //clean everything we get from json theme
        if ($val === null && array_key_exists($fieldID,$tmpArrRender)){
          $decodedData = json_decode($tmpArrRender[$fieldID],true);

          if ($decodedData !== null && is_array($decodedData)){
             array_walk_recursive($decodedData, function(&$item){
               if (!is_array($item)){
                 $item = htmlspecialchars_decode(html_entity_decode($item,ENT_QUOTES));
               }}
             );
            $val = $decodedData;
          } else {
            $val = htmlspecialchars_decode(html_entity_decode($tmpArrRender[$fieldID],ENT_QUOTES));
           }
        }
        
        if ($val !== null){
          // Create leaf
          $fields[$fieldID] = array(
            "type"    =>  $fieldData->getFieldDefinition()->getType(),
            "value"   =>  $val
          );
        }
       
      }
      
      $tmpArr = array(
        "type"     => $entity->getType(),
        "changed"  =>  date("c",$entity->getChangedTime()),
        "fields"   => $fields
      );

      //assign to working array
      $rows[] = $tmpArr;
    }

    // Get the content type configured in the display or fallback to the
    // default.

    if ((empty($this->view->live_preview))){
      $content_type = $this->displayHandler->getContentType();
    } else {
      $content_type = !empty($this->options['formats']) ? reset($this->options['formats']) : 'json';
    }
    
    return $this->serializer->serialize($rows, $content_type);
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
