<?php

/**
 * @file
 * GeocoderArgis class that handles geolocation coordinates from an address.
 */

namespace Drupal\geocoder_arcgis\GeocoderArcgis;

use geoPHP;

/**
 * Class GeocoderArcgis
 */
class GeocoderArcgis {

  /**
   * @var DrupalEnvironment
   */
  protected $env;

  /**
   * @var array
   */
  protected $options;

  /**
   * GeocoderArcgis constructor.
   *
   * @param DrupalEnvironment $env
   *   The DrupalEnvironment
   * @param array $options
   *   Specified options
   */
  public function __construct(DrupalEnvironment $env, array $options) {
    $this->env = $env;
    $this->options = $options;
  }

  /**
   * Get the location coordinates from an address.
   *
   * @param string $address
   *   The specified address
   *
   * @return ArcgisPoint|\MultiPoint
   *   The result
   *
   * @throws ArcgisException
   */
  public function getLocationFromAddress($address) {
    $this->env->loadGeoPHP();

    $geometries = $this->retrieveGeometriesFromAddress($address);

    if ($this->isAllResultsOptionsSet()) {
      return geoPHP::geometryReduce($geometries);
    }

    return $this->extractBestGeometryWithAlternatives($geometries);
  }

  /**
   * Get the candidates from the provided address.
   *
   * @param string $address
   *   The specified address
   *
   * @return array
   *   Geometry points
   *
   * @throws ArcgisException
   */
  protected function retrieveGeometriesFromAddress($address) {
    $results = $this->doHTTPRequest($address);

    $data = $this->extractAndDecodeJSONData($results);

    return $this->processCandidates($data);
  }

  /**
   * Do the HTTP request to retrieve the results from the given address.
   *
   * @param string $address
   *   The url to do the HTTP request to
   *
   * @return object
   *   The results object
   *
   * @throws ArcgisException
   */
  protected function doHTTPRequest($address) {
    $results = $this->env->doHTTPRequest(
      $this->buildUrlWithQuery($address)
    );

    if (isset($results->error)) {
      $args = array(
        '@code' => $results->code,
        '@error' => $results->error,
      );
      $msg = $this->env->translate(
        'HTTP request to ArcGIS failed. Code: @code Error: @error',
        $args
      );
      throw new ArcgisException($msg);
    }

    return $results;
  }

  /**
   * Build the url and query.
   *
   * @param string $address
   *   The specified address
   *
   * @return string
   *   The url with query string
   */
  protected function buildUrlWithQuery($address) {
    $query = http_build_query(
      array(
        'singleLine' => $address,
        'f' => 'json',
      )
    );

    // Default to a secure connection.
    if (!isset($this->options['https']) || $this->options['https']) {
      $protocol = 'https';
    }
    else {
      $protocol = 'http';
    }

    return $protocol . "://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?" . $query;
  }

  /**
   * Decode the data field in the given results object.
   *
   * @param object $results
   *   The results object from the HTTP request
   *
   * @return object
   *   JSON decoded object
   *
   * @throws ArcgisException
   */
  protected function extractAndDecodeJSONData($results) {
    $data = json_decode($results->data);

    if (empty($data->candidates)) {
      $msg = $this->env->translate('ArcGIS could not find any candidates.');
      throw new ArcgisException($msg);
    }

    return $data;
  }

  /**
   * Process the data from ArcGis.
   *
   * @param object $data
   *   JSON decoded result
   *
   * @return array
   *   Geometry points
   *
   * @throws ArcgisException
   */
  protected function processCandidates($data) {
    $geometries = array();

    foreach ($data->candidates as $result) {
      if ($this->validateCandidate($result)) {
        continue;
      }

      if ($this->validateScoreThreshold($result)) {
        continue;
      }

      $geometries[] = $this->createArcgisPoint($result);
    }

    if (empty($geometries)) {
      $msg = $this->env->translate('ArcGIS did not return any valid candidates.');
      throw new ArcgisException($msg);
    }

    return $geometries;
  }

  /**
   * Validate if the candidate result has all the required fields.
   *
   * @param object $result
   *   Candidate result
   *
   * @return bool
   *   True if valid, else false
   */
  protected function validateCandidate($result) {
    return empty($result->location->x) || empty($result->location->y) ||
    empty($result->score) || empty($result->address);
  }

  /**
   * Validate if the candidate result meets the score threshold.
   *
   * @param object $result
   *   Candidate result
   *
   * @return bool
   *   True if met, else false
   */
  protected function validateScoreThreshold($result) {
    return isset($this->options['score_threshold']) && $result->score < $this->options['score_threshold'];
  }

  /**
   * Create the ArcgisPoint from the given result.
   *
   * @param object $result
   *   Candidate result
   *
   * @return ArcgisPoint
   *   Geometry point
   */
  protected function createArcgisPoint($result) {
    $arcgis_point = new ArcgisPoint($result->location->x, $result->location->y);

    // Add additional metadata to the geometry.
    $arcgis_point->data['geocoder_score'] = $result->score;
    $arcgis_point->data['geocoder_address'] = $result->address;

    return $arcgis_point;
  }

  /**
   * Check if the all_results options is set.
   *
   * @return bool
   *   True when set, false otherwise
   */
  protected function isAllResultsOptionsSet() {
    return !empty($this->options['all_results']);
  }

  /**
   * Get the best Geometry from the list of Geometries.
   *
   * The others will be added as alternatives in the data field.
   *
   * @param array $geometries
   *   ArcgisPoint array
   *
   * @return ArcgisPoint
   *   Best ArcgisPoint
   */
  protected function extractBestGeometryWithAlternatives(array $geometries) {
    // The canonical geometry is the first result (best guess).
    $geometry = array_shift($geometries);

    if (count($geometries)) {
      $geometry->data['geocoder_alternatives'] = $geometries;
    }

    return $geometry;
  }
}
