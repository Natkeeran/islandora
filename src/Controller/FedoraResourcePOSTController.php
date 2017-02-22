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
 *
 * Class FedoraResourcePOSTController.
 *
 * @package Drupal\islandora\Controller
 */
class FedoraResourcePOSTController extends ControllerBase {

    const HEADER_CONTENT_TYPE = 'Content-Type';
    const HEADER_BUNDLE = 'X-Islandora-Bundle';

    public $jsonld_generator;

    public function __construct(JsonldContextGeneratorInterface $generator) {
        $this->jsonld_generator = $generator;
    }

    public static function create(ContainerInterface $container) {
        return new static($container->get('islandora.jsonldcontextgenerator'));
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function processPost(Request $request){

        // Get Headers
        $contentType = $request->headers->get(self::HEADER_CONTENT_TYPE);
        $bundle = $request->headers->get(self::HEADER_BUNDLE);
        $entity_type = 'fedora_resource';

        // If X-Islandora-Bundle is not set, return bad request
        if (!isset($bundle) || $bundle == ''){
            $response['data'] = "X-Islandora-Bundle header not defined";
            return new JsonResponse($response, 400);
        }

        // If bundle specified in X-Islandora-Bundle is not a valid one, return bad request
        $bundles =\Drupal::entityManager()->getBundleInfo($entity_type);
        if (!array_key_exists($bundle, $bundles)) {
            $response['data'] = "Bundle not found.";
            return new JsonResponse($response, 400);
        }

        // Request Body
        $body = json_decode( $request->getContent(), TRUE );

        // Field -> RDF Mapping, i.e [name] => Array([0] => dc11:title [1] => rdf:label
        $arrFieldsWithRDFMapping = $this->getFieldsWithRDFMapping($entity_type, $bundle);

        // We need this context info to map the property to the field
        // arrFieldsWithRDFMapping does not have context?!, it only has prefix!
        //  [dc11] => http://purl.org/dc/elements/1.1/
        $arrNameSpaces = $this->getBundleContext($entity_type, $bundle);

        // Create Entity
        $entity = entity_create($entity_type, array('type' => $bundle));

        // Loop through all properties
        foreach($body as $property => $fieldValue){
            // This gets auto created!
            if($property === "@type"){
                continue;$dump = print_r($variable, true);
            }

            // Expanded triple $property i.e "http://purl.org/dc/elements/1.1/title": [{ "@value": "This is a RDF Source" }]
            // Gets converted into dc11:title
            $propertyName = substr($property, strrpos($property, '/') + 1);
            $nameSpaceURL = substr($property, 0, strrpos($property, "/"));
            $nsPrefix = array_search($nameSpaceURL . "/", $arrNameSpaces);
            $prefixAndProp = $nsPrefix . ":" . $propertyName;

            $fileName = $this->getFieldName($arrFieldsWithRDFMapping,  $prefixAndProp);
            $value = $fieldValue[0]["@value"];
            $entity->$fileName = $value;
        }

        $entity->save();
        $id = $entity->id();

        $response['data'] = 'created entity with id ' . $id;
        return new JsonResponse($response, 201);
    }

    /**
     * Gets the context of the bundle
     *
     * @param string $entity_type
     * @param string $bundle
     * @return array
     *      An array of context urls indexed by prefix
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

    /**
     * Returns the RDF Mapping of the fields, if RDF Mapping is available
     * rdfmapping -> fieldname, where rdfmapping is prefix:propertyName
     *
     * @param string $entity_type
     * @param string $bundle
     * @return array $arrFieldsWithRDFMapping
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
            if(isset($arrFieldMapping['properties']) && count($arrFieldMapping["properties"]) > 0){
                $arrFieldsWithRDFMapping[$field_name] = $arrFieldMapping["properties"];
            }
        }

        return $arrFieldsWithRDFMapping;
    }

    /**
     * Loop through all rdf field mappings
     * If a field is found for an rdf mapping (prefix:Property), return that field
     *
     * @param $arrFieldsWithRDFMapping
     * @param string $rdfProperty
     * @return string
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
