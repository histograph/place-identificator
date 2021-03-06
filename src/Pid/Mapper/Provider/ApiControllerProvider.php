<?php

namespace Pid\Mapper\Provider;

use Histograph\Sources;
use Pid\Mapper\Model\Dataset;
use Pid\Mapper\Model\Status;
use Pid\Mapper\Service\GeocoderService;
use Silex\Application;
use Silex\ControllerProviderInterface;

use Silex\Provider\FormServiceProvider;
use SimpleUser\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Straightforward controller to provide a way to perform some simple storage actions through ajax calls
 *
 * @package Pid\Mapper\Provider
 */
class ApiControllerProvider implements ControllerProviderInterface
{

    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->get('/record/unmap/{id}',
            array(new self(), 'clearStandardization'))->bind('api-clear-mapping')->assert('id', '\d+');
        $controllers->get('/record/map/{id}',
            array(new self(), 'setStandardization'))->bind('api-set-mapping')->assert('id', '\d+');
        $controllers->get('/record/ummappable/{id}',
            array(new self(), 'setUnmappable'))->bind('api-unmappable')->assert('id', '\d+');

        $controllers->post('/record/choose-pit/{id}',
            array(new self(), 'choosePit'))->bind('api-choose-pit')->assert('id', '\d+');

        return $controllers;
    }

    /**
     * Delete the found standardized info for a certain record and reset it to an UNMAPPED status
     *
     * @param Application $app
     * @param integer $id
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function clearStandardization(Application $app, $id)
    {
        if ($app['dataset_service']->clearRecord($id)) {
            return $app->json(array('id' => $id));
        }

        return $app->json(array('error' => 'Record could not be updated'), 503);
    }


    /**
     * Delete the found standardized info for a certain record and reset it to an UNMAPPED status
     *
     * @param Application $app
     * @param integer $id
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function setUnmappable(Application $app, $id)
    {
        if ($ids = $app['dataset_service']->setRecordAsUnmappable($id)) {
            return $app->json($ids);
        }

        return $app->json(array('error' => 'Record could not be updated'), 503);
    }

    /**
     * Save a manually set mapping
     *
     * @param Application $app
     * @param integer $id
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function setStandardization(Application $app, Request $request, $id)
    {
        //$data = $request->getContent();
        $uri = $request->get('uri');

        if (empty($uri) || !is_string($uri)) {
            return $app->json(array('error' => 'Geen of geen valide uri ontvangen. Er is niets opgeslagen.'), 400);
        }

        // only gg, geonames and tgn uri's!
        if (!preg_match("/gemeentegeschiedenis.nl\/gemeentenaam/", $uri) &&
            !preg_match("/geonames.org/", $uri) &&
            !preg_match("/vocab.getty.edu\/tgn/", $uri)
        ) {
            // no longer returning an error, now simply storing whatever the user fills in
            // return $app->json(array('error' => 'Geen GG, TGN of GeoNames Uri. Er is niets opgeslagen.'), 400);

            $data['hg_dataset'] = Sources::discoverSourceType($uri);
            $data['hg_uri'] = $uri;

            if ($ids = $app['dataset_service']->storeManualMapping($data, $id)) {
                return $app->json($ids);
            }
        }

        // if geonames, we do'nt want the last part they keep communicating!
        if (preg_match("/(http:\/\/sws.geonames.org\/[0-9]+)(\/.*)/", $uri, $matches)) {
            $uri = $matches[1];
        }

        try {
            $uriData = $app['uri_resolver_service']->findOne($uri);

            if (false === $uriData) { // probably means uri could not be found, so we will not be storing the geometry
                $data['hg_dataset'] = Sources::discoverSourceType($uri);
                $data['hg_uri'] = $uri;

                if ($ids = $app['dataset_service']->storeManualMapping($data, $id)) {
                    return $app->json($ids);
                }
            } else {
                $data = $uriData;

                $data['hg_geometry'] = json_encode($data['geometry']);
                $data['hg_dataset'] = Sources::discoverSourceType($uri);

                if ($ids = $app['dataset_service']->storeManualMapping($data, $id)) {
                    return $app->json($ids);
                }
            }

        } catch (\RuntimeException$e) {
            return $app->json(array('id' => $id), 404);
        } catch (\Exception $e) {
            $app['monolog']->error($e->getMessage());

            return $app->json(array('id' => $id), 503);
        }
    }

    /**
     * When selecting one PIT of multiple results we need to store the entire klont that get's passed in as json
     *
     * @param Application $app
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function choosePit(Application $app, Request $request, $id)
    {
        $jsonData = json_decode($data = $request->getContent());
        $data = [];

        $pit = $jsonData->properties->pits[0];

        if (property_exists($pit, 'id')) {
            $data['hg_id'] = $pit->id;
        }
        $data['hg_uri'] = $pit['uri'];
        $data['hg_name'] = $pit['name'];
        $data['hg_type'] = $pit['type'];
        $data['hg_dataset'] = $pit['dataset'];
        if ($pit['geometryIndex'] > -1) {
            $data['hg_geometry'] = $jsonData->geometry->geometries[$pit['geometryIndex']];
        }

        if ($app['dataset_service']->storeManualMapping($data, $id)) {
            return $app->json(array('id' => $id));
        }

        return $app->json(array('id' => $id), 503);
    }

}
