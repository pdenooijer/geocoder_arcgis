<?php

/**
 * @file
 * CTools Plugin to provide a Geocoder Handler using ArcGIS.
 *
 * Based on the Google Geocoder CTools plugin.
 */

/**
 * Plugins are described by creating a $plugin array which will be used
 * by the system that includes this file.
 */
$plugin = array(
  'title' => t('ArcGIS'),
  'description' => t('Geocoding with ArcGIS'),
  'callback' => 'geocoder_arcgis',
  'field_types' => array(
    'addressfield',
    'computed',
    'location',
    'taxonomy_term_reference',
    'text',
    'text_long',
    'text_with_summary',
  ),
  'field_callback' => 'geocoder_arcgis_field',
  'settings_callback' => 'geocoder_arcgis_form',
);

/**
 * Process the address data.
 *
 * @param array $address
 *   Address array
 * @param array $options
 *   Array with options set in the backend
 *
 * @return \GeometryCollection|NULL
 *   Geometry collection result or NULL if none/error
 */
function geocoder_arcgis($address, $options = array()) {
  try {
    geophp_load();

    $query = array(
      'singleLine' => $address,
      'f' => 'json',
    );

    if (!isset($options['https']) || $options['https']) {
      $protocol = 'https';
    }
    else {
      $protocol = 'http';
    }

    $url = url(
      $protocol . "://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates",
      array('query' => $query)
    );
    $results = drupal_http_request($url);

    if (isset($results->error)) {
      $args = array(
        '@code' => $results->code,
        '@error' => $results->error,
      );
      $msg = t('HTTP request to ArcGIS failed.\nCode: @code\nError: @error', $args);
      throw new Exception($msg);
    }

    $data = json_decode($results->data);
    if (empty($data->candidates)) {
      $msg = t('ArcGIS could not find any candidates.');
      throw new Exception($msg);
    }

    $geometries = array();
    foreach ($data->candidates as $result) {
      if (empty($result->location->x) || empty($result->location->y) ||
          empty($result->score) || empty($result->address)) {
        continue;
      }

      if (isset($options['score_threshold']) && $result->score < $options['score_threshold']) {
        continue;
      }

      $geom = new Point($result->location->x, $result->location->y);

      // Add additional metadata to the geometry.
      $geom->data = array();
      $geom->data['geocoder_score'] = $result->score;
      $geom->data['geocoder_address'] = $result->address;

      $geometries[] = $geom;
    }

    if (empty($geometries)) {
      $msg = t('ArcGIS did not return any valid candidates.');
      throw new Exception($msg);
    }

    // Check if we should return all results as a compound geometry.
    if (!empty($options['all_results'])) {
      return geoPHP::geometryReduce($geometries);
    }

    // The canonical geometry is the first result (best guess).
    $geometry = array_shift($geometries);

    // If there are any other geometries, these are auxiliary geometries
    // that represent "alternatives".
    if (count($geometries)) {
      $geometry->data['geocoder_alternatives'] = $geometries;
    }

    return $geometry;
  }
  catch (Exception $e) {
    watchdog_exception('geocoder', $e);
    return NULL;
  }
}

/**
 * Get the data from the field and return a geometry object.
 *
 * @param array $field
 *   Input field
 * @param array $field_item
 *   Input field values
 * @param array $options
 *   Array with options set in the backend
 *
 * @return \GeometryCollection|NULL
 *   Geometry collection result or NULL if none/error
 */
function geocoder_arcgis_field($field, $field_item, $options = array()) {
  if ($field['type'] == 'computed' ||
      $field['type'] == 'text' ||
      $field['type'] == 'text_long' ||
      $field['type'] == 'text_with_summary') {
    return geocoder_arcgis($field_item['value'], $options);
  }
  if ($field['type'] == 'addressfield') {
    $address = geocoder_widget_parse_addressfield($field_item);
    return geocoder_arcgis($address, $options);
  }
  if ($field['type'] == 'location') {
    $address = geocoder_widget_parse_locationfield($field_item);
    return geocoder_arcgis($address, $options);
  }
  if ($field['type'] == 'taxonomy_term_reference') {
    $term = taxonomy_term_load($field_item['tid']);
    if ($term) {
      return geocoder_arcgis($term->name, $options);
    }
  }
  return NULL;
}

/**
 * Form to set the ArcGIS options.
 *
 * @param array $default_values
 *   Array containing the default or set values
 *
 * @return array
 *   Form array
 */
function geocoder_arcgis_form($default_values = array()) {
  $form = array();

  $form['https'] = array(
    '#type' => 'checkbox',
    '#title' => t('Use HTTPS connection'),
    '#description' => t('Use a secure connection to the ArcGIS service.'),
    '#default_value' => isset($default_values['https']) ? $default_values['https'] : 1,
  );

  $form['all_results'] = array(
    '#type' => 'checkbox',
    '#title' => t('All geocode results'),
    '#description' => t('Check this to return all results as a Multi-Geometry (MultiPoint or MultiPolygon).'),
    '#default_value' => isset($default_values['all_results']) ? $default_values['all_results'] : 0,
  );

  $form['score_threshold'] = array(
    '#type' => 'select',
    '#title' => t('Minimum score threshold for a results te be valid.'),
    '#description' => t('Selecting a higher value will limit the amount of results by ArcGIS score.'),
    '#options' => array(
      0 => t('0 (default)'),
      25 => '25',
      50 => '50',
      75 => '75',
      85 => '85',
      95 => '95',
      97 => '97',
      98 => '98',
      99 => '99',
    ),
    '#default_value' => isset($default_values['score_threshold']) ? $default_values['score_threshold'] : 0,
  );

  return $form;
}
