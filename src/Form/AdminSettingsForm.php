<?php

namespace Drupal\bus_reservation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure example settings for this site.
 */
class AdminSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'bus_reservation.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return static::SETTINGS;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'bus_reservation.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);
    $form['notify_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email для оповещения'),
      '#default_value' => $config->get('notify_email'),
    ];

    $form['min_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Мнимальное количество человек при котором происходит заказ автобуса'),
      '#default_value' => $config->get('min_capacity'),
    ];
      $form['form_item'] = array(
          '#type' => 'markup',
          '#prefix' => '<a href="/bus-reservation">Страница для резервирования автобуса</a>',
      );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->config(static::SETTINGS)
      // Set the submitted configuration setting.
      ->set('notify_email', $form_state->getValue('notify_email'))
      // You can set multiple configurations at once by making
      // multiple calls to set().
      ->set('min_capacity', $form_state->getValue('min_capacity'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
