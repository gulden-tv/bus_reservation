<?php
namespace Drupal\bus_reservation;

class BusReservation {

/**
* Drupal's settings manager.
*/
protected $settings;

  /**
  * Constructor.
  */
  public function __construct() {
    $this->settings = \Drupal::config('bus_reservation.settings');
  }
}
