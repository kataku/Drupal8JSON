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

  private function add_array_recursive(&$array,$key,$addKey,$addValue=array()){    
    foreach($array as $k => &$v){
      if($k === $key){          
          //if it matches
          if (is_array($v)){            
            //merge our array in if it's an array
            $array[$k][$addKey] = $addValue;
            return true;
          }else{
            //set the target key to our value otherwise
            $array[$k] = array($addKey => $addValue);
            return true;
          }
      }elseif(is_array($v)){                    
        //the recursive bit        
        $this->add_array_recursive($v,$key,$addKey,$addValue);        
      }     
    }
    return true;
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
      $tmpArrRender = $this->view->rowPlugin->render($row);
      
      /**
       * @var $entity \Drupal\node\Entity\Node
       * @var $fieldData \Drupal\Core\Field\FieldItemList|\Drupal\Core\Field\EntityReferenceFieldItemList
       * @var $imageData \Drupal\image\Plugin\Field\FieldType\ImageItem|\Drupal\Core\Entity\Plugin\DataType\EntityReference
       * @var $imageEntity \Drupal\Core\Entity\Plugin\DataType\EntityReference|\Drupal\file\Entity\File
       */
      $fields = array();
      $fieldGroup = array();      
      $entity = $row->_entity;
      
      //could there be groups?
       if(function_exists('field_group_load_field_group')){
          $groupingData = field_group_info_groups($entity->getEntityTypeId(), $entity->bundle(), 'view', 'api_structure');        
          
          if (is_array($groupingData) && count($groupingData) > 0){
              
              foreach($groupingData as $groupName => $group){
                $parent = $group->parent_name;
                 if (empty($parent)){
                    //if we're a base group add us to fields
                    $fields[$groupName] = array();
                 }else{
                    //if we have a parent find it and add ourselves  
                    $this->add_array_recursive($fields,$parent,$groupName);
                 }
                
                //store group names
                foreach($group->children as $childName){
                  if (substr($childName,0,6)!=="group_"){
                    $fieldGroup[$childName] = $groupName;                    
                  }
                }
              }
          }
      }
     
      foreach($entity->getFields() as $fieldID => $fieldData){
        $val = null;
        
        // if of type image
        if($fieldData->getFieldDefinition()->getType() === "image"){
          if(array_key_exists($fieldID,$tmpArrRender)) {
            $val = array();
            foreach($fieldData as $imageData){
              $imageEntity = $imageData->getProperties(true)['entity']->getValue();
              $val[] = array(
                "alt"     =>  $imageData->getValue()['alt'],
                "file"    =>  str_replace(array("http:","https:"),"",file_create_url(file_build_uri($imageEntity->getFilename()))),
                "type"    =>  $imageEntity->getMimeType(),
                "size"    =>  $imageEntity->getSize()
              );
            }
          }
        }

        // if of type entity_reference
        if($fieldData->getFieldDefinition()->getType() === "entity_reference"){
          if(array_key_exists($fieldID,$tmpArrRender)) {
            $val = $tmpArrRender[$fieldID];
            $valDecode = json_decode($val, TRUE);
            
            if ($fieldID == "field_technologies"){
              $val = array();
              foreach($fieldData->getValue() as $referencedTechnology){
                $node = node_load($referencedTechnology['target_id']); 
                $title = $node->getFields()['title']->getValue()[0]['value'];
                if (array_key_exists(0,$node->getFields()['field_marketing_url']->getValue())){
                  $url = $node->getFields()['field_marketing_url']->getValue()[0]['value'];
                }else{
                  $url = "";
                }
                $val[] = array(
                  "title" => $title,
                  "field_marketing_url" => $url
                );
              }
            }else{
              if ($valDecode !== NULL && is_array($valDecode)) {
                $val = $valDecode;
                array_walk_recursive($val, function(&$item){
                 if (!is_array($item)){
                   $item = htmlspecialchars_decode(html_entity_decode(urldecode($item),ENT_QUOTES));
                 }}
                );              
              }
            }
          }
        }
        //make boolean a literal bool rather than string
        if($fieldData->getFieldDefinition()->getType() === "boolean"){
          if(array_key_exists($fieldID,$tmpArrRender)) {
            $val = $tmpArrRender[$fieldID];
            if($val){
              $val = true;
            } else {
              $val = false;
            }
          }
        }

        //clean everything we get from json theme
        if ($val === null && array_key_exists($fieldID,$tmpArrRender)){
          $decodedData = json_decode($tmpArrRender[$fieldID],true);          
          if ($decodedData !== null && is_array($decodedData)){
             array_walk_recursive($decodedData, function(&$item){
               if (!is_array($item)){
                 $item = htmlspecialchars_decode(html_entity_decode(urldecode($item),ENT_QUOTES));
               }}
             );
            $val = $decodedData;
          } else {
            $val = htmlspecialchars_decode(html_entity_decode(urldecode($tmpArrRender[$fieldID]),ENT_QUOTES));
           }
        }
        
        if ($val !== null and !empty($val)){
          // Create leaf
          $leaf = array(
            "type"    =>  $fieldData->getFieldDefinition()->getType(),
            "value"   =>  $val
            );    
          
          if (isset($fieldGroup[$fieldID])){
            $groupName = $fieldGroup[$fieldID];              
            $this->add_array_recursive($fields,$groupName,$fieldID,$leaf);
          }else{            
            $fields[$fieldID] = $leaf;            
          }
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
