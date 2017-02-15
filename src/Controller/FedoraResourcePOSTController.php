<?php
namespace Drupal\islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;


/**
 * Class FedoraResourcePOSTController.
 *
 * @package Drupal\islandora\Controller
 */
class FedoraResourcePOSTController extends ControllerBase {

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
        $entity = entity_create($entity_type, array('type' => 'rdf_source'));
        $entity->name = "test entity";
        $entity->save();
        $id = $entity->id();

        $response['data'] = 'created entity with id' + $id;

        return new JsonResponse( $response );

    }

}
