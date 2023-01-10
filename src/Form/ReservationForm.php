<?php
/**
 * @file
 * Contains \Drupal\bus_reservation\Form\ReservationForm.
 */
namespace Drupal\bus_reservation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Html;

class ReservationForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bus_reservation_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $reserved_buses = $this->getReservedBuses();
    $min_capacity = \Drupal::config('bus_reservation.settings')->get('min_capacity');

    foreach ($this->getSchedule() as $day => $value) {
      if( date("N", strtotime($day)) <= 5 ) { // skip holiday
        $form[$day] = array(
          '#type' => 'checkboxes',
          '#title' => t(date("l", strtotime($day))) . " " . $day,
          '#options' => $value['buses'],
          '#disabled' => ($this->isReservationDisable($day) ? TRUE : FALSE) // disable current day
        );
        foreach ($value['buses'] as $time => $b) {
          if (isset($reserved_buses[$day][$time])) {
            $progress = "";
            for ($i = 0; $i < $reserved_buses[$day][$time]['capacity']; $i++) {
              $progress .= '<i class="fa-solid fa-user"></i> ';
            }
            for ($i = $reserved_buses[$day][$time]['capacity']; $i < $min_capacity; $i++) {
              $progress .= '<i class="fa-regular fa-user"></i> ';
            }
            if ($reserved_buses[$day][$time]['capacity'] >= $min_capacity)
              $progress .= '<i class="fa-solid fa-check"></i>';
            $form[$day][$time] = ['#description' => $progress];

            if ($reserved_buses[$day][$time]['disable'] == 1) { // current user already make reservation
             // $form[$day]['#disabled'] = TRUE;
              $form[$day]['#default_value'][] = $time;
            }

          } else {
            $progress = "";
            for ($i = 0; $i < $min_capacity; $i++) {
              $progress .= '<i class="fa-regular fa-user"></i> ';
            }
            $form[$day][$time] = ['#description' => $progress];
          }
        }
      }
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Забронировать'),
      '#button_type' => 'primary',
    );
    $form['#attached']['library'][] = 'bus_reservation/bus-form-styling';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reserved_buses = $this->getReservedBuses();
    $min_capacity = \Drupal::config('bus_reservation.settings')->get('min_capacity');
    foreach ($form_state->getValues() as $day => $buses) {
      if($day == 'submit')
        break;
      foreach ($buses as $time => $reserved) {
        if ($reserved != 0 && (!isset($reserved_buses[$day][$time]) || $reserved_buses[$day][$time]['disable'] == 0)) {
          if($this->createBusNode($day . " " . $time)) {
            \Drupal::messenger()->addMessage(t("Вы успешно забронировали автобус:"));
            \Drupal::messenger()->addMessage($day . ' ' . $reserved);
            if(isset($reserved_buses[$day][$time]) && $reserved_buses[$day][$time]['capacity'] == $min_capacity-1) {
              // send email notification
              \Drupal::messenger()->addMessage('Автобус зарезервирован');
              $this->sendNotification($day . " " . $time);
            }
          }
        }
      }
    }
  }

  function getSchedule() {
    $vid = 'bus';
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);
    //var_dump($terms);
    foreach ($terms as $term) {
      $buses[] = array(
        'tid' => $term->tid,
        'name' => $term->name,
        'departure' =>  \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term->tid)->get('field_departure')->getValue()[0]['value']
      );
    }
    for($i=0; $i<7; $i++) {
      $d = strtotime($i . " days");
      $r[date('Y-m-d',$d)]['date'] = date("Y-m-d", $d);
      foreach ($buses as $b)
        $r[date('Y-m-d',$d)]['buses'][date("H:i", $b['departure'])] = $b['name'];
    }
    return $r;
  }

  protected function createBusNode($datetime) {
    $new_bus = \Drupal\node\Entity\Node::create(['type' => 'bus']);
    $new_bus->set('title', $datetime);
    $new_bus->set('body', 'Bus reservation');
    $new_bus->enforceIsNew();
    $new_bus->save();
    return true;
  }

  function getReservedBuses() {
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'bus')
      ->execute();
    $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
    $uid = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id())->get('uid')->value;
    $buses = array(array());
    foreach ($nodes as $n) {
      $daytime = explode(" ", $n->getTitle());
      if(isset($buses[$daytime[0]][$daytime[1]]))
        $buses[$daytime[0]][$daytime[1]]['capacity']++;
      else {
        $buses[$daytime[0]][$daytime[1]]['capacity'] = 1;
        $buses[$daytime[0]][$daytime[1]]['disable'] = 0;
      }
      $buses[$daytime[0]][$daytime[1]]['uid'] = $n->getOwnerId();
      if($uid == $n->getOwnerId())
        $buses[$daytime[0]][$daytime[1]]['disable'] = 1;
    }
    return $buses;
  }

  function sendNotification($msg) {
    $mailManager = \Drupal::service('plugin.manager.mail');
    $module = 'bus_reservation';
    $key = 'bus_reservation_notification'; // Replace with Your key
    $to = \Drupal::config('bus_reservation.settings')->get('notify_email');
    $params['message'] = 'Требуется автобус ' . $msg;
    $params['title'] = 'New bus reservation';
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = true;

    $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    if ($result['result'] != true) {
      $message = t('There was a problem sending your email notification to @email.', array('@email' => $to));
      \Drupal::messenger()->addMessage($message, 'error');
      \Drupal::logger('mail-log')->error($message);
      return $message;
    }

    $message = t('An email notification has been sent to @email ', array('@email' => $to));
    // \Drupal::messenger()->addMessage($message);
    \Drupal::logger('mail-log')->notice($message);
    return $msg;
  }
  function isReservationDisable($day) {
      if($day == date("Y-m-d"))
          return True;
      if( date("H", strtotime("now"))>=8 && date("Y-m-d", strtotime("+1 day")) == $day )
          return True;
      return False;
  }
}


