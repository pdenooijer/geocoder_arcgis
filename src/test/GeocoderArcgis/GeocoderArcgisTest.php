<?php

namespace Drupal\geocoder_arcgis\test\GeocoderArcgis;

use Drupal\geocoder_arcgis\GeocoderArcgis\ArcgisPoint;
use Drupal\geocoder_arcgis\GeocoderArcgis\GeocoderArcgis;

/**
 * Class GeocoderArcgisTest
 */
class GeocoderArcgisTest extends \PHPUnit_Framework_TestCase {

  /**
   * @test
   */
  public function shouldHandleResultError() {
    $result = (object) array('code' => 0, 'error' => 'Test error');
    $env = $this->getDrupalEnvironmentMock($result);
    $geocoder = new GeocoderArcgis($env, array());

    $this->setExpectedException(
      'Drupal\geocoder_arcgis\GeocoderArcgis\ArcgisException',
      'HTTP request to ArcGIS failed. Code: 0 Error: Test error'
    );
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldHandleEmptyCandidates() {
    $result = (object) array('data' => '{"candidates":[]}');
    $env = $this->getDrupalEnvironmentMock($result);
    $geocoder = new GeocoderArcgis($env, array());

    $this->setExpectedException(
      'Drupal\geocoder_arcgis\GeocoderArcgis\ArcgisException',
      'ArcGIS could not find any candidates.'
    );
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldHandleEmptyValidCandidates() {
    $options = array('score_threshold' => 99.3);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);

    $this->setExpectedException(
      'Drupal\geocoder_arcgis\GeocoderArcgis\ArcgisException',
      'ArcGIS did not return any valid candidates.'
    );
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldHandleDefaultRequestSecure() {
    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, array());
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldHandleSecureRequest() {
    $options = array('https' => TRUE);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldHandleNonSecureRequest() {
    $url = 'http://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?singleLine=test&f=json';
    $options = array('https' => FALSE);

    $env = $this->getDrupalEnvironmentMock($this->getResults(), $url);
    $geocoder = new GeocoderArcgis($env, $options);
    $geocoder->getLocationFromAddress('test');
  }

  /**
   * @test
   */
  public function shouldReturnLocationFromAddress() {
    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, array());
    $result = $geocoder->getLocationFromAddress('test');

    $this->checkArcgisPoint($result);

    $this->assertSameSize(
      range(0, 18),
      $result->data['geocoder_alternatives']
    );
  }

  /**
   * @test
   */
  public function shouldReturnAllResults() {
    $options = array('all_results' => 1);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);
    $result = $geocoder->getLocationFromAddress('test');

    $this->assertInstanceOf('\MultiPoint', $result);
    $this->assertObjectHasAttribute('components', $result);
    $this->assertSameSize(range(0, 19), $result->components);
  }

  /**
   * @test
   */
  public function shouldReturnPointAndAllResultsWithScoreThreshold() {
    $options = array('score_threshold' => 80);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);
    $result = $geocoder->getLocationFromAddress('test');

    $this->checkArcgisPoint($result);
    $this->assertSameSize(range(0, 18), $result->data['geocoder_alternatives']);
  }

  /**
   * @test
   */
  public function shouldReturnPointAndLessResultsWithScoreThreshold() {
    $options = array('score_threshold' => 81);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);
    $result = $geocoder->getLocationFromAddress('test');

    $this->checkArcgisPoint($result);
    $this->assertSameSize(range(0, 6), $result->data['geocoder_alternatives']);
  }

  /**
   * @test
   */
  public function shouldReturnPointAndOnlyPreciseResultsWithScoreThreshold() {
    $options = array('score_threshold' => 99);

    $env = $this->getDrupalEnvironmentMock($this->getResults());
    $geocoder = new GeocoderArcgis($env, $options);
    $result = $geocoder->getLocationFromAddress('test');

    $this->checkArcgisPoint($result);
    $this->assertSameSize(range(0, 1), $result->data['geocoder_alternatives']);
    $this->assertEquals(
      'Gildeweg 39, 4383 NJ Vlissingen',
      $result->data['geocoder_alternatives'][0]->data['geocoder_address']
    );
    $this->assertEquals(
      'Gildeweg, 4383 NJ Vlissingen',
      $result->data['geocoder_alternatives'][1]->data['geocoder_address']
    );
  }

  /**
   * Do assert checks on the default ArcgisPoint object.
   *
   * @param ArcgisPoint $point
   *   The ArcgisPoint to check.
   */
  private function checkArcgisPoint($point) {
    $this->assertInstanceOf('Drupal\geocoder_arcgis\GeocoderArcgis\ArcgisPoint', $point);
    $this->assertObjectHasAttribute('data', $point);
    $this->assertObjectHasAttribute('coords', $point);

    $this->assertArraySubset(
      array(
        'geocoder_score' => 99.29,
        'geocoder_address' => 'Gildeweg 39a, 4383 NJ Vlissingen',
        'geocoder_alternatives' => array(),
      ),
      $point->data,
      TRUE
    );

    $this->assertEquals(3.5884433460004, $point->getX(), '', 0.0000001);
    $this->assertEquals(51.457227826, $point->getY(), '', 0.0000001);
  }

  /**
   * Set up the test cases.
   *
   * @param object $result
   *   The HTTP request result
   * @param string $url
   *   The request url
   *
   * @return \PHPUnit_Framework_MockObject_MockObject
   */
  private function getDrupalEnvironmentMock($result, $url = '') {
    if (!$url) {
      $url = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?singleLine=test&f=json';
    }

    $geocoder = $this->getMockBuilder('\Drupal\geocoder_arcgis\GeocoderArcgis\DrupalEnvironment')
      ->setMethods(array('loadGeoPHP', 'doHTTPRequest', 'translate'))
      ->getMock();

    $geocoder->expects($this->once())
      ->method('loadGeoPHP')
      ->will($this->returnValue(TRUE));

    $geocoder->expects($this->once())
      ->method('doHTTPRequest')
      ->with($this->equalTo($url))
      ->will($this->returnValue($result));

    $geocoder->method('translate')
      ->will($this->returnCallback(function($string, $replacements = array()) {
        return strtr($string, $replacements);
      }));

    return $geocoder;
  }

  /**
   * Default results for a query to find the ibuildings location.
   *
   * @return object
   */
  private function getResults() {
    return (object) array('data' => <<<'JSON'
{"spatialReference":{"wkid":4326,"latestWkid":4326},"candidates":[{"address":"Gildeweg 39a, 4383 NJ Vlissingen","location":{"x":3.5884433460004175,"y":51.457227826000462},"score":99.290000000000006,"attributes":{},"extent":{"xmin":3.5874429999999999,"ymin":51.456228000000003,"xmax":3.5894430000000002,"ymax":51.458227999999998}},{"address":"Gildeweg 39, 4383 NJ Vlissingen","location":{"x":3.5884371570004419,"y":51.457114383000487},"score":99.290000000000006,"attributes":{},"extent":{"xmin":3.587437,"ymin":51.456113999999999,"xmax":3.5894370000000002,"ymax":51.458114000000002}},{"address":"Gildeweg 39, 4383 NJ Vlissingen","location":{"x":3.5880481940004074,"y":51.457290954000484},"score":97.159999999999997,"attributes":{},"extent":{"xmin":3.5870479999999998,"ymin":51.456291,"xmax":3.589048,"ymax":51.458291000000003}},{"address":"Gildeweg, 4383 NJ Vlissingen","location":{"x":3.5861873470004184,"y":51.457559536000474},"score":99.290000000000006,"attributes":{},"extent":{"xmin":3.5831870000000001,"ymin":51.454560000000001,"xmax":3.5891869999999999,"ymax":51.460560000000001}},{"address":"Gildeweg, 4383 NH Vlissingen","location":{"x":3.5847100650004222,"y":51.455466027000512},"score":97.420000000000002,"attributes":{},"extent":{"xmin":3.5827100000000001,"ymin":51.453465999999999,"xmax":3.5867100000000001,"ymax":51.457465999999997}},{"address":"Gildeweg, 4383 NK Vlissingen","location":{"x":3.586973725000405,"y":51.457393987000501},"score":97.420000000000002,"attributes":{},"extent":{"xmin":3.5829740000000001,"ymin":51.453394000000003,"xmax":3.5909740000000001,"ymax":51.461393999999999}},{"address":"Gildeweg, 4383 Vlissingen","location":{"x":3.5867838870004221,"y":51.455724218000512},"score":97.420000000000002,"attributes":{},"extent":{"xmin":3.5837840000000001,"ymin":51.452724000000003,"xmax":3.5897839999999999,"ymax":51.458723999999997}},{"address":"4383 NJ Vlissingen","location":{"x":3.5869531080004435,"y":51.45739830700046},"score":97.450000000000003,"attributes":{},"extent":{"xmin":3.5839530000000002,"ymin":51.454397999999998,"xmax":3.5899529999999999,"ymax":51.460397999999998}},{"address":"4383 AS Vlissingen","location":{"x":3.5563337940004089,"y":51.454993635000505},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.553334,"ymin":51.451993999999999,"xmax":3.5593340000000002,"ymax":51.457993999999999}},{"address":"4383 AN Vlissingen","location":{"x":3.556785029000423,"y":51.455960029000494},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5547849999999999,"ymin":51.453960000000002,"xmax":3.5587849999999999,"ymax":51.45796}},{"address":"4383 AV Vlissingen","location":{"x":3.5573795990004555,"y":51.456720755000504},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.55538,"ymin":51.454720999999999,"xmax":3.55938,"ymax":51.458720999999997}},{"address":"4383 AL Vlissingen","location":{"x":3.5578004400004488,"y":51.455534413000464},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5548000000000002,"ymin":51.452534,"xmax":3.5608,"ymax":51.458534}},{"address":"4383 AE Vlissingen","location":{"x":3.5579213900004447,"y":51.454985337000494},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5559210000000001,"ymin":51.452984999999998,"xmax":3.5599210000000001,"ymax":51.456985000000003}},{"address":"4383 AJ Vlissingen","location":{"x":3.5581200140004512,"y":51.455445015000464},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5571199999999998,"ymin":51.454445,"xmax":3.5591200000000001,"ymax":51.456445000000002}},{"address":"4383 AH Vlissingen","location":{"x":3.5581550400004289,"y":51.455190040000502},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5571549999999998,"ymin":51.454189999999997,"xmax":3.5591550000000001,"ymax":51.456189999999999}},{"address":"4383 AA Vlissingen","location":{"x":3.5583063360004417,"y":51.454339193000465},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5563060000000002,"ymin":51.452339000000002,"xmax":3.5603060000000002,"ymax":51.456339}},{"address":"4383 AK Vlissingen","location":{"x":3.5583100030004289,"y":51.455310026000461},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5573100000000002,"ymin":51.45431,"xmax":3.55931,"ymax":51.456310000000002}},{"address":"4383 AR Vlissingen","location":{"x":3.558698181000409,"y":51.456188324000493},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.555698,"ymin":51.453187999999997,"xmax":3.5616979999999998,"ymax":51.459187999999997}},{"address":"4383 AP Vlissingen","location":{"x":3.5588758670004381,"y":51.456185574000472},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.555876,"ymin":51.453186000000002,"xmax":3.5618759999999998,"ymax":51.459186000000003}},{"address":"4383 AW Vlissingen","location":{"x":3.5589900630004081,"y":51.456786706000457},"score":80.650000000000006,"attributes":{},"extent":{"xmin":3.5569899999999999,"ymin":51.454787000000003,"xmax":3.5609899999999999,"ymax":51.458787000000001}}]}
JSON
    );
  }
}
