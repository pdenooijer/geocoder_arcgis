<?php

/**
 * @file
 * DrupalEnvironment class, used as a Adapter to all Drupal functions.
 *
 * This way we can easily mock them for testing purposes.
 */

namespace Drupal\geocoder_arcgis\GeocoderArcgis;

use Exception;

/**
 * Class DrupalEnvironment.
 *
 * This class main purpose is to make GeocoderArcgis testable without the need
 * for external libraries like Drupal and the Geocoder module.
 */
class DrupalEnvironment {

  /**
   * Load the GeoPHP includes.
   *
   * @throws \Exception
   *   Throws exception when the required geo php libraries are not loaded.
   */
  public function loadGeoPhp() {
    if (!geophp_load()) {
      $msg = t('Failed to load geoPHP from the Geocoder module.');
      throw new Exception($msg);
    }
  }

  /**
   * Do the Drupal HTTP request.
   *
   * @param string $url
   *   The url to do the request to.
   *
   * @return object
   *   The result from the request.
   */
  public function doHttpRequest($url) {
    return drupal_http_request($url);
  }

  /**
   * Translate a string with the Drupal translate function.
   *
   * @param string $string
   *   English string to translate.
   * @param array $replacements
   *   An associative array of replacements to make after translation.
   *
   * @return string
   *   Translated string.
   */
  public function translate($string, array $replacements = array()) {
    // Ignore 'Only string literals should be passed to t() where possible'.
    // @codingStandardsIgnoreLine
    return t($string, $replacements);
  }

}
