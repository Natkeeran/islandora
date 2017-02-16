<?php
namespace Drupal\islandora\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\islandora\RdfBundleSolver\JsonldContextGeneratorInterface;
use Drupal\rdf\RdfMappingInterface;
use Drupal\rdf\Entity\RdfMapping;



/**
 * Class FedoraResourcePOSTController.
 *
 * @package Drupal\islandora\Controller
 */
class FedoraResourcePOSTController extends ControllerBase {

    public $jsonld_generator;

    public function __construct(JsonldContextGeneratorInterface $generator) {
        $this->jsonld_generator = $generator;
    }

    public static function create(ContainerInterface $container) {
        return new static($container->get('islandora.jsonldcontextgenerator'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function processPost(Request $request){

        // Get Headers
        $contentType = $request->headers->get('Content-Type');
        $islandoraBundle = $request->headers->get('X-Islandora-Bundle');

        // Request Body
        $body = json_decode( $request->getContent(), TRUE );


        $entity_type = 'fedora_resource';
        $bundle = 'rdf_source';

        // This gives us prefix + Property mapping
        $arrFieldsWithRDFMapping = $this->getFieldsWithRDFMapping($entity_type, $bundle);

        // We need this context info to map the property to the field
        // arrFieldsWithRDFMapping does not have context?!, it only has prefix!
        $arrNameSpaces = $this->getBundleContext($entity_type, $bundle);

        // Create Entity
        $entity = entity_create($entity_type, array('type' => $bundle));

        // Loop through all properties
        foreach($body as $property => $value){
            if($property === "@type"){
                continue;
            }
            $propertyName = substr($property, strrpos($property, '/') + 1);
            $nameSpaceURL = substr($property, 0, strrpos($property, "/"));
            $nsPrefix = array_search($nameSpaceURL . "/", $arrNameSpaces);
            $prefixAndProp = $nsPrefix . ":" . $propertyName;

            $key = $this->getFieldName($arrFieldsWithRDFMapping,  $prefixAndProp);
            $value = $value[0]["@value"];
            $entity->$key = $value;
        }

        $entity->save();
        $id = $entity->id();

        $response['data'] = 'created entity with id' . $id;
        return new JsonResponse( $response );
    }

    /*
     * Returns context urls indexed by prefix
     */
    private function getBundleContext($entity_type, $bundle){
        $bundleContext = $this->jsonld_generator->getContext($entity_type . "." . $bundle);
        $arrBundleContext = json_decode($bundleContext, true);

        // Get all Namespaces
        $arrNameSpaces = array();
        foreach($arrBundleContext["@context"] as $k => $v){
            if (strpos($k, ':') === false) {
                $arrNameSpaces[$k] = $v;
            }
        }
        return $arrNameSpaces;
    }

    /*
     * Returns the RDF Mapping of the fields, if RDF Mapping is available
     * rdfmapping -> fieldname, where rdfmapping is prefix:propertyName
     */
    private function getFieldsWithRDFMapping($entity_type, $bundle){
        // Get Fields
        $entityManager = \Drupal::service('entity_field.manager');
        $fields = $entityManager->getFieldDefinitions($entity_type, $bundle);

        // Get RDF Mapping
        $rdfMapping = RdfMapping::load($entity_type . "." . $bundle);

        $arrFieldsWithRDFMapping = array();
        foreach ($fields as $field_name => $field_definition) {
            $arrFieldMapping = $rdfMapping->getFieldMapping($field_name);
            if(count($arrFieldMapping["properties"]) > 0){
                $arrFieldsWithRDFMapping[$field_name] = $arrFieldMapping["properties"];
            }
        }

        return $arrFieldsWithRDFMapping;
    }

    /*
     * Loop through all rdf field mappings
     * If a field is found for an rdf mapping (prefix:Property), return that field
     */
    private function getFieldName($arrFieldsWithRDFMapping, $rdfProperty){
        $fieldName = false;
        foreach($arrFieldsWithRDFMapping as $k => $v){
            $found = array_search($rdfProperty, $v);
            if ($found !== false) {
                $fieldName = $k;
                break;
            }
        }
        return $fieldName;
    }


}
