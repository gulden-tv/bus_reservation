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
    $months = array( 1 => 'января' , 'февраля' , 'марта' , 'апреля' , 'мая' , 'июня' , 'июля' , 'августа' , 'сентября' , 'октября' , 'ноября' , 'декабря' );
    $reserved_buses = $this->getReservedBuses();
    // dump($reserved_buses);
    $min_capacity = \Drupal::config('bus_reservation.settings')->get('min_capacity');

    foreach ($this->getSchedule() as $from => $days) {
      $last_from = "";
      foreach ($days as $day => $buses) {
        if( date("N", strtotime($day)) <= 5 ) { // skip holiday
          foreach ($buses as $time) {
            $form['buses'][$day . $from] = array(
              '#type' => 'checkboxes',
              '#title' => t(date("l", strtotime($day))) . " " . date("j", strtotime($day)) . " " . $months[date("n", strtotime($day))],
              '#disabled' => ($this->isReservationDisable($day) ? TRUE : FALSE) // disable current day
            );
          }
          if($last_from != $from) { // show h1 only one time
            $form['buses'][$day . $from]['#prefix'] = '<h2>' . $from . '</h2>';
            $last_from = $from;
          }
          foreach ($buses as $date) {
            $form['buses'][$day.$from]['#options'][$date] = date("H:i", strtotime($date));
            if (isset($reserved_buses[$date])) {
              $progress = "";
              for ($i = 0; $i < $reserved_buses[$date]['capacity']; $i++) {
                $progress .= '<i class="fa-solid fa-user"></i> ';
              }
              for ($i = $reserved_buses[$date]['capacity']; $i < $min_capacity; $i++) {
                $progress .= '<i class="fa-regular fa-user"></i> ';
              }
              if ($reserved_buses[$date]['capacity'] >= $min_capacity)
                $progress .= '<i class="fa-solid fa-check"></i>';

              if ($reserved_buses[$date]['disable'] == 1) { // current user already make reservation
                // $form[$day]['#disabled'] = TRUE; // if want to disable current day
                $form['buses'][$day.$from]['#default_value'][$date] = $date;
              }
            } else {
              $progress = "";
              for ($i = 0; $i < $min_capacity; $i++) {
                $progress .= '<i class="fa-regular fa-user"></i> ';
              }
            }
            $form['buses'][$day.$from][$date] = ['#description' => $progress];
          }
        } // end skip holiday
      }
    }
    $form['#limit_validation_errors'] = array(array('map'));
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Забронировать'),
      '#button_type' => 'primary',
      '#limit_validation_errors' => array(),
    );
    $form['#attached']['library'][] = 'bus_reservation/bus-form-styling';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reserved_buses = $this->getReservedBuses();
    $min_capacity = \Drupal::config('bus_reservation.settings')->get('min_capacity');
    $user_choose = $form_state->getUserInput();
    // $form_state->disableRedirect();
    foreach ($user_choose as $from => $dates) {
      if(is_array($dates)) foreach ($dates as $date) {
        if($date == "")
          continue;
        list($day, $time) = explode(" ", $date);
        if ($time != null && (!isset($reserved_buses[$date]) || $reserved_buses[$date]['disable'] == 0)) {
          $from = str_replace($day, "", $from);
          if($this->createBusNode($date, $from)) {
            \Drupal::messenger()->addMessage(t("Вы успешно забронировали автобус:"));
            \Drupal::messenger()->addMessage($date);
            if(isset($reserved_buses[$date]) && $reserved_buses[$date]['capacity'] == $min_capacity-1) {
              // send email notification
              \Drupal::messenger()->addMessage('Автобус зарезервирован');
              $this->sendNotification($from . " " . $date);
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
      // $r[date('Y-m-d',$d)]['date'] = date("Y-m-d", $d);
      foreach ($buses as $b)
        // $r[$b['name']][date('Y-m-d',$d)]['buses'][date("H:i", $b['departure'])] = $b['name'];
        $r[$b['name']][date('Y-m-d',$d)][] = date('Y-m-d ',$d) . date("H:i", $b['departure']);
    }
    // dump($r);
    // \Drupal::messenger()->addMessage($r);
    return $r;
  }

  protected function createBusNode($datetime, $body = "") {
    $new_bus = \Drupal\node\Entity\Node::create(['type' => 'bus']);
    $new_bus->set('title', $datetime);
    $new_bus->set('body', $body);
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
      $date = $n->getTitle();
      if(isset($buses[$date]))
        $buses[$date]['capacity']++;
      else {
        $buses[$date]['capacity'] = 1;
        $buses[$date]['disable'] = 0;
      }
      $buses[$date]['uid'] = $n->getOwnerId();
      if($uid == $n->getOwnerId())
        $buses[$date]['disable'] = 1;
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
          return True; // блокируем следующий день если уже позже 8 утра
      if (date("w", strtotime($day)) == 1 && ( ( date("wH", strtotime("now")) >= 508 ) || date("w", strtotime("now")) == 0) )
          return True; // блокируем понедельник если сегодня уже пятница и позже 8 утра
      return False;
  }
  function bus_reservation_form_validate($element, &$form_state, $form) {

  }
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }
}


