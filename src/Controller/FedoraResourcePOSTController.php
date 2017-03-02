<?php

namespace Drupal\islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\islandora\RdfBundleSolver\JsonldContextGeneratorInterface;
use Drupal\rdf\Entity\RdfMapping;

/**
 * Class FedoraResourcePOSTController.
 *
 * @package Drupal\islandora\Controller
 */
class FedoraResourcePOSTController extends ControllerBase {

  const HEADER_CONTENT_TYPE = 'Content-Type';
  const HEADER_BUNDLE = 'X-Islandora-Bundle';

  protected $jsonldGenerator;
  protected $entityManager;
  protected $entityFieldManager;

  /**
   * FedoraResourcePOSTController constructor.
   *
   * @param \Drupal\islandora\RdfBundleSolver\JsonldContextGeneratorInterface $generator
   *   Needed to get bundle's rdf context.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entityManager
   *   Needed to do error checking.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Needed to get the fields info.
   */
  public function __construct(JsonldContextGeneratorInterface $generator, EntityManagerInterface $entityManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->jsonldGenerator = $generator;
    $this->entityManager = $entityManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Create required static object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *    Have to review this method!.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('islandora.jsonldcontextgenerator'),
      $container->get('entity.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Main method to handle the post request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *    HTTP Rquest.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *    HTTP Response.
   */
  public function processPost(Request $request) {

    // Get Headers.
    $contentType = $request->headers->get(self::HEADER_CONTENT_TYPE);
    $bundle = $request->headers->get(self::HEADER_BUNDLE);
    $entity_type = 'fedora_resource';

    // If X-Islandora-Bundle is not set, return bad request.
    if (!isset($bundle) || $bundle == '') {
      $response['data'] = "X-Islandora-Bundle header not defined";
      return new JsonResponse($response, 400);
    }

    // If X-Islandora-Bundle is not a valid one, return bad request.
    $bundles = $this->entityManager->getBundleInfo($entity_type);
    if (!array_key_exists($bundle, $bundles)) {
      $response['data'] = "Bundle not found.";
      return new JsonResponse($response, 400);
    }

    try {
      // Request Body.
      $content = json_decode($request->getContent(), TRUE);
      $newResourceID = $this->createEntity($entity_type, $bundle, $content);
      $responseData = 'created entity with id ' . $newResourceID;
      $responseStatus = 201;

    }
    catch (Exception $e) {
      watchdog('islandora', $e->getMessage(), array(), WATCHDOG_ERROR);
      $responseData = "Failed to create entity.";
      $responseStatus = 500;
    }

    $response['data'] = $responseData;
    return new JsonResponse($response, $responseStatus);
  }

  /**
   * Business Logic to create an entity from POST request.
   *
   * @param string $entity_type
   *   Fedora_resource.
   * @param string $bundle
   *   Type of bundle to be created, i.e image, collection.
   * @param object $content
   *   The request body.
   *
   * @return string
   *   Id of the entity that was created.
   */
  private function createEntity($entity_type, $bundle, $content) {
    // Field -> RDFMapping - [name] => Array([0] => dc11:title [1] => rdf:label.
    $arrFieldsWithRDFMapping = $this->getFieldsWithRdfMapping($entity_type, $bundle);

    // We need this context info to map the property to the field.
    // arrFieldsWithRDFMapping does not have context?!, it only has prefix!.
    // [dc11] => http://purl.org/dc/elements/1.1/.
    $arrNameSpaces = $this->getBundleContext($entity_type, $bundle);

    // Create Entity.
    $entity = entity_create($entity_type, array('type' => $bundle));

    // Loop through all properties.
    foreach ($content as $property => $fieldValue) {
      // This gets auto created!
      if ($property === "@type") {
        continue;
      }

      // Expanded triple $property.
      // i.e "http://purl.org/dc/elements/1.1/title": [{ "@value": "Example" }].
      // Gets converted into dc11:title.
      $propertyName = substr($property, strrpos($property, '/') + 1);
      $nameSpaceURL = substr($property, 0, strrpos($property, "/"));
      $nsPrefix = array_search($nameSpaceURL . "/", $arrNameSpaces);
      $prefixAndProp = $nsPrefix . ":" . $propertyName;

      $fieldName = $this->getFieldName($arrFieldsWithRDFMapping, $prefixAndProp);
      if ($fieldName) {
        $value = $fieldValue[0]["@value"];
        $entity->$fieldName = $value;
      }
    }

    $entity->save();
    $id = $entity->id();
    return $id;
  }

  /**
   * Gets the context of the bundle.
   *
   * @param string $entity_type
   *    Entity type's name.
   * @param string $bundle
   *    Bundle's name.
   *
   * @return array
   *      An array of context urls indexed by prefix
   */
  private function getBundleContext($entity_type, $bundle) {
    $bundleContext = $this->jsonldGenerator->getContext($entity_type . "." . $bundle);
    $arrBundleContext = json_decode($bundleContext, TRUE);

    // Get all Namespaces.
    $arrNameSpaces = array();
    foreach ($arrBundleContext["@context"] as $k => $v) {
      if (strpos($k, ':') === FALSE) {
        $arrNameSpaces[$k] = $v;
      }
    }
    return $arrNameSpaces;
  }

  /**
   * Returns the RDF Mapping of the fields, if RDF Mapping is available.
   *
   * @param string $entity_type
   *    Entity Type's name.
   * @param string $bundle
   *    Bundle's name.
   *
   * @return array
   *   Field to RDF Mapping.
   */
  private function getFieldsWithRdfMapping($entity_type, $bundle) {
    $arrFieldsWithRDFMapping = array();

    // Get Fields.
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    // Get RDF Mapping.
    $rdfMapping = RdfMapping::load($entity_type . "." . $bundle);

    if (!$rdfMapping) {
      throw new Exception("RDFMapping Not Found");
    }

    foreach ($fields as $field_name => $field_definition) {
      $arrFieldMapping = $rdfMapping->getFieldMapping($field_name);
      if (isset($arrFieldMapping['properties']) && count($arrFieldMapping["properties"]) > 0) {
        $arrFieldsWithRDFMapping[$field_name] = $arrFieldMapping["properties"];
      }
    }

    return $arrFieldsWithRDFMapping;
  }

  /**
   * Loop through all rdf field mappings and return the field name.
   *
   * @param array $arrFieldsWithRDFMapping
   *   An array fieldName -> prefix:Property mapping.
   * @param string $rdfProperty
   *   Property (prefix:Property).
   *
   * @return string
   *   Fieldname of the triple
   */
  private function getFieldName(array $arrFieldsWithRDFMapping, $rdfProperty) {
    $fieldName = FALSE;
    foreach ($arrFieldsWithRDFMapping as $k => $v) {
      $found = array_search($rdfProperty, $v);
      if ($found !== FALSE) {
        $fieldName = $k;
        break;
      }
    }
    return $fieldName;
  }

}
