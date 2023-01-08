<?php
/**
 * @file
 * Contains \Drupal\bus_reservation\Controller\BusReservationController.
 */
namespace Drupal\bus_reservation\Controller;

class BusReservationController {
  public function content() {
    return array(
      '#type' => 'markup',
      '#markup' => $this->week(),
    );
  }

  function week() {
    $week = [ "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday" ];
    $today = date('N')-1;
    $yestoday = date('N', strtotime( "-1 day")) -1;
    $vid = 'bus';
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);
    foreach ($terms as $term) {
      $buses[] = array(
        'id' => $term->tid,
        'name' => $term->name
      );
    }
    $table = "<table>";
    for($i=$yestoday; $i<7+$today; $i++) {
      $d = strtotime($i - $today . " day");
      $table .= "<th>" . $week[$i % 7] . "</br>" . date("d M", $d) . "</th>";
    }

    foreach ($buses as $bus) {
      $table .= "<tr>";
      $class = "active";
      for($i=$yestoday; $i<7+$today; $i++) {
        if ($i % 7 < 5)
          $table .= "<td>" . $bus['name'] . "</td>";
        else
          $table .= "<td>holiday</td>";
      }
      $table .= "</tr>";
    }
    $table .= "</table>";

    return $table;
  }
}
