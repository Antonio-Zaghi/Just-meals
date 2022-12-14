<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Order;
use App\Customer;
use App\Item;
use App\User;
use App\Restaurant;
use App\Mail\PaymentMail;
use App\Mail\AdminMail;
use Illuminate\Support\Facades\Mail;


class PayDatesController extends Controller
{
    public function checkUserData(Request $request){
        $data = $request->all();

        $mailFound = false;
        $nameFound = false;
        $surnameFound = false;
        $fullnameFound = false;

        $CustomerEmail = Customer::where('email', $data['email'])->get();

        $customerFullName = Customer::where([
            ['name', '=', $data['name']],
            ['surname', '=', $data['surname']],
        ])->get();
        
        foreach ($customerFullName as $customerConFullnameTrovata){
            if($customerConFullnameTrovata){
                $fullnameFound = true;
            }
        }

        foreach ($CustomerEmail as $customerConMailTrovata){
            if($customerConMailTrovata){
                $mailFound = true;
            }
        }

        if($mailFound && $fullnameFound){
            return response()->json('old');
        } else if ($mailFound){
            return response()->json('wrong');
        } else {
            return response()->json('new');
        }
    }


    public function postPayment(Request $request){

        $data = $request->all();
   
        $gateway = new \Braintree\Gateway([
            'environment' => getenv('BRAINTREE_ENV'),
            'merchantId' => getenv('BRAINTREE_MERCHANT_ID'),
            'publicKey' => getenv('BRAINTREE_PUBLIC_KEY'),
            'privateKey' => getenv('BRAINTREE_PRIVATE_KEY')
        ]);


        $amount = $data['total_price'];
        $nonce = $data['paymentMethodNonce'];

        $result = $gateway->transaction()->sale([
            'paymentMethodNonce' => $nonce,
            'amount' => $amount,
            'options' => [
                'submitForSettlement' => true
            ]
        ]);

        if($result->success){

            $userData = $data['user_dates']['userData'];
            $transItems = $data['transaction']['items'];
    
            if($data['customer'] === 'old'){
                $customer = Customer::where('email', $userData['email'])->first();
            } else {
                $customer = new Customer();
                $customer->name = $userData['name'];
                $customer->surname = $userData['surname'];
                $customer->email = $userData['email'];
                $customer->address = $userData['address'];
                $customer->save();
            }

            // ORDINE
            $newOrder = new Order();
    
            $newOrder->customer_id = $customer->id;
            $newOrder->delivery_time = $userData['delivery_time'];
            $newOrder->note = $userData['note'];
            $itemsOrdered = [];

            $count = 1;
            foreach($transItems as $item){
            
                ${"newItem".$count} = [
                    "stats" => Item::where("name", $item['name'])->first(),
                    'quantity' => $item['quantity']
                ];

                $itemsOrdered[] = ${"newItem".$count};

            $count++;
        }
            
            $newOrder->total_price = $amount;
            
            $newOrder->save();
            
            foreach($itemsOrdered as $singleItem){
                $price = $singleItem['stats']['price'];
    
                $newOrder->items()->attach(
                    $singleItem['stats'],
                    [
                        'quantity' => $singleItem['quantity'],
                        'item_price' => $price
                    ]
                );
    
            }
            

            // mail customer post pagamento
            Mail::to($customer->email)->send(new PaymentMail($customer->name, $customer->surname, $amount, $itemsOrdered, $result->transaction->id));

            // mail ristoratore 
            $itemCalc = $itemsOrdered[0];

            $restaurantId = $itemCalc['stats']->restaurant_id;

            $restaurant = Restaurant::findOrFail($restaurantId);

            $userId = $restaurant->user_id;

            $user_restaurant = User::findOrFail($userId);

            Mail::to($user_restaurant->email)->send(new AdminMail($customer->name, $customer->surname, $amount, $itemsOrdered, $result->transaction->id));

            return response()->json($result);

        } else {
            return response()->json($result);
        }




        


    }
}
