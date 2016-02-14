<?php

/**
 * @file
 * ArcgisPoint class, used to add public data variable to \Point class.
 */

namespace Drupal\geocoder_arcgis\GeocoderArcgis;

/**
 * Class ArcgisPoint
 */
class ArcgisPoint extends \Point {

  /**
   * @var array
   */
  public $data = array();
}
