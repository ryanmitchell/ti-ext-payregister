<?php namespace SamPoyigi\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;

class Stripe extends BasePaymentGateway
{
    public function onRender()
    {
        $this->load->model('stripe/Stripe_model');

        $this->lang->load('stripe/stripe');

        $this->addJs('https://js.stripe.com/v2/', 'stripe-js');
        $this->addJs(extension_url('stripe/assets/jquery-stripe-payment.js'), 'stripe-payment-js');
        $this->addJs(extension_url('stripe/assets/process-stripe.js'), 'process-stripe-js');

        $data['code'] = $this->setting('code');
        $data['title'] = $this->setting('title', $data['code']);
        $data['description'] = $this->setting('description', lang('text_description'));
        $data['force_ssl'] = $this->setting('force_ssl', '1');
        $transaction_mode = $this->setting('transaction_mode', 'test');
        $publishable_key = ($transaction_mode == 'live') ? 'live_publishable_key' : 'test_publishable_key';
        $data['publishable_key'] = $this->setting($publishable_key);

        $order_data = $this->session->userdata('order_data');                           // retrieve order details from session userdata
        $data['payment'] = !empty($order_data['payment']) ? $order_data['payment'] : '';
        $data['minimum_order_total'] = $this->setting('order_total', 0);
        $data['order_total'] = $this->cart->total();

        if ($this->input->post('stripe_token')) {
            $data['stripe_token'] = $this->input->post('stripe_token');
        }
        else {
            $data['stripe_token'] = '';
        }

        if (isset($this->input->post['stripe_cc_number'])) {
            $padsize = (strlen($this->input->post['stripe_cc_number']) < 7 ? 0 : strlen($this->input->post['stripe_cc_number']) - 7);
            $data['stripe_cc_number'] = substr($this->input->post['stripe_cc_number'], 0, 4).str_repeat('X', $padsize).substr($this->input->post['stripe_cc_number'], -3);
        }
        else {
            $data['stripe_cc_number'] = '';
        }

        if (isset($this->input->post['stripe_cc_exp_month'])) {
            $data['stripe_cc_exp_month'] = $this->input->post('stripe_cc_exp_month');
        }
        else {
            $data['stripe_cc_exp_month'] = '';
        }

        if (isset($this->input->post['stripe_cc_exp_year'])) {
            $data['stripe_cc_exp_year'] = $this->input->post('stripe_cc_exp_year');
        }
        else {
            $data['stripe_cc_exp_year'] = '';
        }

        if (isset($this->input->post['stripe_cc_cvc'])) {
            $data['stripe_cc_cvc'] = $this->input->post('stripe_cc_cvc');
        }
        else {
            $data['stripe_cc_cvc'] = '';
        }

        // pass array $data and load view files
        $this->load->view('stripe/stripe', $data);
    }

    public function getHiddenFields()
    {
        return [
            'stripe_publishable_key' => '',
            'stripe_token' => '',
        ];
    }

    public function processPaymentForm($data, $host, $order)
    {
        $this->lang->load('stripe/stripe');

        $this->form_validation->reset_validation();
        $this->form_validation->set_rules('stripe_token', 'lang:label_card_number', 'required');

        if ($this->form_validation->run() === TRUE) {                                            // checks if form validation routines ran successfully
            $validated = TRUE;
        }
        else {
            return FALSE;
        }

        $order_data = $this->session->userdata('order_data');                        // retrieve order details from session userdata
        $cart_contents = $this->session->userdata('cart_contents');                                                // retrieve cart contents

        if ($validated === TRUE AND !empty($order_data['payment']) AND $order_data['payment'] == 'stripe') {    // check if payment method is equal to paypal

            if (empty($order_data) OR empty($cart_contents)) {
                return FALSE;
            }

            $payment_settings = !empty($order_data['payment_settings']) ? $order_data['payment_settings'] : [];

            if (!empty($payment_settings['order_total']) AND $cart_contents['order_total'] < $payment_settings['order_total']) {
                $this->alert->set('danger', lang('alert_min_total'));

                return FALSE;
            }

            $this->load->model('Stripe_model');
            $response = $this->Stripe_model->createCharge($this->input->post('stripe_token'), $order_data);

            if (isset($response->error->message)) {
                if ($response->error->type === 'card_error') $this->alert->set('danger', $response->error->message);
            }
            else if (isset($response->status)) {

                if ($response->status !== 'succeeded') {
                    $order_data['status_id'] = $payment_settings['order_status'];
                }
                else if (isset($payment_settings['order_status']) AND is_numeric($payment_settings['order_status'])) {
                    $order_data['status_id'] = $payment_settings['order_status'];
                }
                else {
                    $order_data['status_id'] = $this->config->item('default_order_status');
                }

                if (!empty($response->paid)) {
                    $comment = sprintf(lang('text_payment_status'), $response->status, $response->id);
                }
                else {
                    $comment = "{$response->failure_message} {$response->id}";
                }

                $order_history = [
                    'object_id'  => $order_data['order_id'],
                    'status_id'  => $order_data['status_id'],
                    'notify'     => '0',
                    'comment'    => $comment,
                    'date_added' => mdate('%Y-%m-%d %H:%i:%s', time()),
                ];

                $this->load->model('Statuses_model');
                $this->Statuses_model->addStatusHistory('order', $order_history);

                $this->load->model('Orders_model');
                if ($this->Orders_model->completeOrder($order_data['order_id'], $order_data, $cart_contents)) {
                    $this->redirect('checkout/success');                                    // $this->redirect to checkout success page with returned order id
                }
            }

            return FALSE;
        }
    }
}