<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class TranskripRequest extends CI_Controller {

    public function __construct() {
        parent::__construct();
        try {
            $this->Auth_model->checkModuleAllowed(get_class());
        } catch (Exception $ex) {
            $this->session->set_flashdata('error', $ex->getMessage());
            header('Location: /');
        }
        $this->load->library('BlueTape');
        $this->load->model('Transkrip_model');
        $this->load->database();
    }

    public function index() {
        // Retrieve logged in user data
        $userInfo = $this->Auth_model->getUserInfo();
        // Retrieve requests for this user
        $requests = $this->Transkrip_model->requestsBy($userInfo['email']);
        $forbiddenTypes = $this->Transkrip_model->requestTypesForbidden($requests);
        foreach ($requests as &$request) {
            if ($request->answer === NULL) {
                $request->status = 'TUNGGU';
                $request->labelClass = 'secondary';
            } else if ($request->answer === 'printed') {
                $request->status = 'TERCETAK';
                $request->labelClass = 'success';
            } else if ($request->answer === 'rejected') {
                $request->status = 'DITOLAK';
                $request->labelClass = 'alert';
            }
            $request->requestDateString = $this->bluetape->dbDateTimeToReadableDate($request->requestDateTime);
            $request->requestByName = $this->bluetape->getName($request->requestByEmail);
            $request->answeredDateString = $this->bluetape->dbDateTimeToReadableDate($request->answeredDateTime);
        }
        unset($request);

        $this->load->view('TranskripRequest/main', array(
            'currentModule' => get_class(),
            'requestByEmail' => $userInfo['email'],
            'requestByNPM' => $this->bluetape->getNPM($userInfo['email'], '-'),
            'requestByName' => $userInfo['name'],
            'requests' => $requests,
            'forbiddenTypes' => $forbiddenTypes
        ));
    }

    public function add() {
        try {
            date_default_timezone_set("Asia/Jakarta");
            $userInfo = $this->Auth_model->getUserInfo();
            $requests = $this->Transkrip_model->requestsBy($userInfo['email']);
            $forbiddenTypes = $this->Transkrip_model->requestTypesForbidden($requests);
            if (is_string($forbiddenTypes)) {
                throw new Exception($forbiddenTypes);
            }
            $requestType = htmlspecialchars($this->input->post('requestType'));
            if (in_array($requestType, $forbiddenTypes)) {
                throw new Exception("Tidak bisa, karena transkrip $requestType sudah pernah dicetak di semester ini.");
            }
            // --disable-xss-auditor
            // how to sql injection -> test','printed',null,null,null) -- -
            // how to script injection -> <script>window.location.replace("https://youtu.be/dQw4w9WgXcQ");</script>
            // how to csrf attack -> open otherPage.html then click that link
            $time = strftime('%Y-%m-%d %H:%M:%S'); 
            $requestUsage = $this->input->post('requestUsage');
            $this->db->query("INSERT INTO `Transkrip` (`requestByEmail`,
            `requestDateTime`, `requestType`, `requestUsage`, `answer`, `answeredByEmail`, `answeredDateTime`, `answeredMessage`) 
            VALUES ('$userInfo[email]', '$time', '$requestType', '$requestUsage', null, null, null, null)");
            $this->session->set_flashdata('info', 'Permintaan cetak transkrip sudah dikirim. Silahkan cek statusnya secara berkala di situs ini.');

            $this->load->model('Email_model');
            $recipients = $this->config->item('roles')['tu.ftis'];
            if (is_array($recipients)) {
                foreach ($recipients as $email) {
                    $requestByName = $this->bluetape->getName($userInfo['email']);
                    $subject = "Permohonan Transkrip dari $requestByName";
                    $message = $this->load->view('TranskripRequest/email', array(
                        'name' => $this->bluetape->getName($email),
                        'requestByName' => $requestByName
                    ), TRUE);
                    $this->Email_model->send_email($email, $subject, $message);
                }
            }
        } catch (Exception $e) {
            $this->session->set_flashdata('error', $e->getMessage());
        }
        header('Location: /TranskripRequest');
    }

}
