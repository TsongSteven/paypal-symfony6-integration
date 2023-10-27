<?php

namespace App\Controller;

use App\Form\DonationType;
use App\Entity\Donations;
use App\Service\ValidatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Razorpay\Api\Api;
use Symfony\Component\HttpKernel\KernelInterface;

class PaymentGatewayController extends AbstractController
{
    private $em;
    private  $kernel;
    public function __construct(EntityManagerInterface  $em, KernelInterface $kernel)
    {
        $this->em = $em;
        $this->kernel =  $kernel;
    }

    #[Route(path: '/payment', name: 'app_payment_gateway')]
    public function payment(Request $request, ValidatorService $validator): Response
    {
        $donation = new Donations();
        $form = $this->createForm(DonationType::class, $donation);
        $form->handleRequest($request);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($validator->getErrorMessages($form));
        }

        return $this->render('payment_gateway/index.html.twig', [
            'form' => $form,
        ]);
    }

    private function getAccessToken()
    {
        $cert = $this->kernel->getProjectDir().'/public/cert/cacert.pem';

        if (!empty(Donations::PAYPAL_CLIENT_ID) && !empty(Donations::PAYPAL_CLIENT_SECRET)) {
            $auth = base64_encode(Donations::PAYPAL_CLIENT_ID . ":" . Donations::PAYPAL_CLIENT_SECRET);

            // $apiEndpoint = "https://api.paypal.com"; // For production

            $apiEndpoint = "https://api-m.sandbox.paypal.com"; // ForDev 
            $url = $apiEndpoint . "/v1/oauth2/token";
                
            $data = "grant_type=client_credentials";
        
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
            $headers = [
                "Authorization: Basic $auth",
                "Content-Type: application/x-www-form-urlencoded",
            ];
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CAINFO, $cert);
            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                echo "Failed to generate Access Token: " . curl_error($ch);
            } else {
                $data = json_decode($response, true);
             
                $access_token = $data['access_token'];
                return $access_token;
            }
        
            curl_close($ch);
        } else {
            echo "MISSING_API_CREDENTIALS";
        }
    }

    #[Route(path: '/api/orders', name: 'orders')]
    public function orders(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        
        $url = Donations::BASE_URL.'/v2/checkout/orders';
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => $data['amount']
                    ]
                ]
            ]
        ];

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->getAccessToken(),
        ];
       
        $ch = curl_init($url);
        $cert = $this->kernel->getProjectDir().'/public/cert/cacert.pem';
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $cert);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $cert);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            echo 'Error: ' . curl_error($ch);
        }
        
        curl_close($ch);
        
        return  new JsonResponse($response);
    }
    
    #[Route('/api/orders/{orderID}/capture', name:"capture_order")]
    public function captureOrders($orderID, Request $request)
    {
        $donation = new Donations();
        try {
            $result = $this->captureOrder($orderID);
            $data = json_decode($request->getContent(), true);

            $jsonResponse = $result['jsonResponse'];
            $httpStatusCode = $result['httpStatusCode'];
            
            if(isset($jsonResponse['id']) && Donations::COMPLETED == $jsonResponse['status']) {
                $donation->setAmount($data['amount']);
                $donation->setFullName($data['full_name']);
                $donation->setPhone($data['phone_no']);
                $donation->setPin($data['pin']);
                $donation->setEmail($data['email']);
                $donation->setAddress($data['address']);
                $donation->setPaypalOrderId($orderID);
                $donation->setPaypalTransactionId($jsonResponse['purchase_units'][0]['payments']['captures'][0]['id']);

                $this->em->persist($donation);
                $this->em->flush();

                return new JsonResponse($jsonResponse, $httpStatusCode);
            }else{
                 return new JsonResponse('error');
            }
            return new JsonResponse($jsonResponse, $httpStatusCode);
        } catch (Exception $error) {
            error_log("Failed to create order: " . $error->getMessage());
            return new JsonResponse(['error' => 'Failed to capture order.'], 500);
        }
    }

    public function captureOrder($orderID)
    {
        $cert = $this->kernel->getProjectDir().'/public/cert/cacert.pem';
        try {
            $accessToken = $this->getAccessToken();
            $url = Donations::BASE_URL . "/v2/checkout/orders/".$orderID."/capture";

            $headers = [
                'Content-Type: application/json',
                "Authorization: Bearer $accessToken",
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CAINFO, $cert);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                error_log("Failed to capture order: " . curl_error($ch));
                return new JsonResponse(['error' => 'Failed to capture order.'], 500);
            }

            return $this->handleResponse($response);

        } catch (Exception $error) {
            // error_log("Failed to capture order: " . $error->getMessage());
            // return new JsonResponse(['error' => 'Failed to capture order.'], 500);
            return  $error;
        }
    }

    public function handleResponse($response)
    {
        try {
            $jsonResponse = json_decode($response, true);
            return [
                'jsonResponse' => $jsonResponse,
                'httpStatusCode' => http_response_code(),
            ];
        } catch (Exception $err) {
            $errorMessage = $response; 
            throw new Exception($errorMessage);
        }
    }

    #[Route(path: '/orders/completed/{transactionId}', name: 'orders_completed')]
    public function orderCompleted($transactionId)
    {
        $order = $this->em->getRepository(Donations::class)->findOneBy(['paypal_transaction_id' => $transactionId]);
       
        return $this->render('payment_gateway/res.html.twig',['order' => $order]);
    }
}
