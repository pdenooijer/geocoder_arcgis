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

    $env->loadGeoPHP();
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
  public function getLocation($address) {
    $geometries = $this->retrieveGeometries($address);

    return $this->convertToLocation($geometries);
  }

  /**
   * Retrieve the geometries array from the given address.
   *
   * @param string $address
   *   The specified address
   *
   * @return array
   *   Geometry points
   *
   * @throws ArcgisException
   */
  protected function retrieveGeometries($address) {
    $json = $this->requestJSONEncodedGeometryData($address);

    $data = $this->decodeGeometryData($json);

    return $this->createValidGeometries($data);
  }

  /**
   * Request the JSON string from the given address.
   *
   * @param string $address
   *   The specified address
   *
   * @return string
   *   JSON geometry data
   *
   * @throws ArcgisException
   */
  protected function requestJSONEncodedGeometryData($address) {
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

    return $results->data;
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
   * @param string $json
   *   JSON geometry data
   *
   * @return object
   *   JSON decoded object
   *
   * @throws ArcgisException
   */
  protected function decodeGeometryData($json) {
    $data = json_decode($json);

    if (empty($data->candidates)) {
      $msg = $this->env->translate('ArcGIS could not find any candidates.');
      throw new ArcgisException($msg);
    }

    return $data;
  }

  /**
   * Create valid geometry objects from the data object.
   *
   * @param object $data
   *   JSON decoded data
   *
   * @return array
   *   Geometry points
   *
   * @throws ArcgisException
   */
  protected function createValidGeometries($data) {
    $geometries = $this->validateAndCreateGeometries($data);

    if (empty($geometries)) {
      $msg = $this->env->translate('ArcGIS did not return any valid candidates.');
      throw new ArcgisException($msg);
    }

    return $geometries;
  }

  /**
   * Validate and create the geometries array.
   *
   * @param object $data
   *   JSON decoded
   *
   * @return array
   *   Geometries points
   */
  protected function validateAndCreateGeometries($data) {
    $geometries = array();

    foreach ($data->candidates as $candidate) {
      if (!$this->validateCandidate($candidate)) {
        continue;
      }

      $geometries[] = $this->createArcgisPoint($candidate);
    }

    return $geometries;
  }

  /**
   * Validate if the candidate is valid and meets the score threshold.
   *
   * @param object $candidate
   *   Candidate
   *
   * @return bool
   *   True if valid, else false
   */
  protected function validateCandidate($candidate) {
    return $this->isCandidateValid($candidate) && $this->doesCandidateMeetScoreThreshold($candidate);
  }

  /**
   * Check if a candidate that has all required data.
   *
   * @param object $candidate
   *   Candidate
   *
   * @return bool
   *   True when valid, else false
   */
  protected function isCandidateValid($candidate) {
    return !empty($candidate->location->x) && !empty($candidate->location->y) &&
      !empty($candidate->score) && !empty($candidate->address);
  }

  /**
   * Validate if the candidate result meets the score threshold.
   *
   * @param object $candidate
   *   Candidate
   *
   * @return bool
   *   True if met or not set, else false
   */
  protected function doesCandidateMeetScoreThreshold($candidate) {
    if (isset($this->options['score_threshold'])) {
      return $candidate->score >= $this->options['score_threshold'];
    }
    return TRUE;
  }

  /**
   * Create the ArcgisPoint from the given result.
   *
   * @param object $candidate
   *   Candidate
   *
   * @return ArcgisPoint
   *   Geometry point
   */
  protected function createArcgisPoint($candidate) {
    $arcgis_point = new ArcgisPoint($candidate->location->x, $candidate->location->y);

    // Add additional metadata to the geometry.
    $arcgis_point->data['geocoder_score'] = $candidate->score;
    $arcgis_point->data['geocoder_address'] = $candidate->address;

    return $arcgis_point;
  }

  /**
   * Get the location from the provided geometries.
   *
   * @param array $geometries
   *   ArgisPoint array
   *
   * @return ArcgisPoint|\GeometryCollection
   *   Location
   */
  protected function convertToLocation(array $geometries) {
    if ($this->isOptionAllResultsSet()) {
      return geoPHP::geometryReduce($geometries);
    }

    return $this->extractBestGeometryWithAlternatives($geometries);
  }

  /**
   * Check if the all_results options is set.
   *
   * @return bool
   *   True when set, false otherwise
   */
  protected function isOptionAllResultsSet() {
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
