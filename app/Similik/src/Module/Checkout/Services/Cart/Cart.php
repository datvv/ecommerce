<?php
/**
 * Copyright © Nguyen Huu The <thenguyen.dev@gmail.com>.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Similik\Module\Checkout\Services\Cart;


use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use function GuzzleHttp\Promise\promise_for;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use MJS\TopSort\Implementations\ArraySort;
use function Similik\_mysql;
use function Similik\dispatch_event;
use function Similik\get_config;
use Similik\Services\Http\Request;
use function Similik\subscribe;

class Cart
{
    protected $fields = [];

    /** @var ItemFactory itemFactory*/
    protected $itemFactory;

    /** @var Request $request*/
    protected $request;

    /**@var callable[]*/
    protected $resolvers = [];

    protected $isRunning = false;

    protected $dataSource = [];

    /**@var Promise[] $promises*/
    protected $promises = [];

    /**@var Promise $setDataPromises*/
    protected $setDataPromise;

    protected $error;

    protected $isOrdered = false;

    public function __construct(
        Request $request
    )
    {
        $this->initFields();
        $this->request = $request;

        subscribe('cart_item_updated', function() {
            $this->onChange(null);
        });
        subscribe('cart_item_added', function() {
            $this->onChange(null);
        });
        subscribe('cart_item_removed', function() {
            $this->onChange(null);
        });
    }

    public function initFromId(int $id) {
        if($this->getData("cart_id"))
            throw new \Exception("No, your cart is already initialized");
        $cartData = _mysql()->getTable('cart')->load($id);
        if(!$cartData || $cartData['status'] == 0)
            throw new \Exception("Invalid cart");
        $this->setData('cart_id', $id);
    }

    protected function initFields()
    {
        $fields = [
            'cart_id' => [
                'resolver' => function(Cart $cart) {
                    return $this->dataSource['cart_id'] ?? null;
                }
            ],
            'currency' => [
                'resolver' => function(Cart $cart) {
                    return get_config('general_currency', 'USD');
                }
            ],
            'customer_id' => [
                'resolver' => function(Cart $cart) {
                    return $cart->request->getCustomer()->getData('customer_id');
                }
            ],
            'customer_group_id' => [
                'resolver' => function(Cart $cart) {
                    if($cart->request->getCustomer()->isLoggedIn())
                        return $cart->request->getCustomer()->getData('group_id') ?? 1;
                    else
                        return 999;
                },
                'dependencies' => ['customer_id']
            ],
            'customer_email' => [
                'resolver' => function(Cart $cart) {
                    if($cart->request->getCustomer()->isLoggedIn())
                        $email = $cart->request->getCustomer()->getData('email');
                    else
                        $email = $this->dataSource['customer_email'] ?? null;
                    if(!$email)
                        $this->error = "Customer email could not be empty";

                    return $email;
                },
                'dependencies' => ['customer_id']
            ],
            'customer_full_name' => [
                'resolver' => function(Cart $cart) {
                    if($cart->getData("customer_id"))
                        $name = $cart->request->getCustomer()->getData('full_name');
                    else
                        $name = $this->dataSource['customer_full_name'] ?? null;
                    if(!$name)
                        $this->error = "Customer name could not be empty";

                    return $name;
                },
                'dependencies' => ['customer_id']
            ],
            'user_ip' => [
                'resolver' => function(Cart $cart) {
                    return $cart->request->getClientIp();
                }
            ],
            'user_agent' => [
                'resolver' => function(Cart $cart) {
                    return $cart->request->headers->get('user-agent');
                }
            ],
            'status' => [
                'resolver' => function(Cart $cart) {
                    return  $this->dataSource['status'] ?? $cart->getData('status') ?? 1;
                }
            ],
            'total_qty' => [
                'resolver' => function(Cart $cart) {
                    $count = 0;
                    foreach ($cart->getItems() as $item)
                        $count = $count + (int)$item->getData('qty');

                    return $count;
                },
                'dependencies' => ['items']
            ],
            'total_weight' => [
                'resolver' => function(Cart $cart) {
                    $weight = 0;
                    foreach ($cart->getItems() as $item)
                        $weight += $item->getData('product_weight') * $item->getData('qty');

                    return $weight;
                },
                'dependencies' => ['items']
            ],
            'shipping_fee_excl_tax' => [
                'resolver' => function(Cart $cart) {
                    return (float)dispatch_event('cart_shipping_fee_calculate', [$this]);
                },
                'dependencies' => ['shipping_method', 'total_weight']
            ],
            'shipping_fee_incl_tax' => [
                'resolver' => function(Cart $cart) {
                    return $cart->getData('shipping_fee_excl_tax'); // TODO: Adding tax
                },
                'dependencies' => ['shipping_fee_excl_tax']
            ],
            'tax_amount' => [
                'resolver' => function(Cart $cart) {
                    $itemTax = 0;
                    foreach ($cart->getItems() as $item)
                        $itemTax += $item->getData('tax_amount');
                    return $itemTax + $cart->getData('shipping_fee_incl_tax') - $cart->getData('shipping_fee_excl_tax');
                },
                'dependencies' => ['shipping_fee_incl_tax', 'discount_amount']
            ],
            'sub_total' => [
                'resolver' => function(Cart $cart) {
                    $total = 0;
                    foreach ($cart->getItems() as $item)
                        $total += $item->getData('final_price') * $item->getData('qty');

                    return $total ;
                },
                'dependencies' => ['items']
            ],
            'grand_total' => [
                'resolver' => function(Cart $cart) {
                    return $cart->getData('sub_total') + $cart->getData('tax_amount') + $cart->getData('shipping_fee_incl_tax');
                },
                'dependencies' => ['sub_total', 'tax_amount', 'payment_method', 'shipping_fee_incl_tax']
            ],
            'shipping_address_id' => [
                'resolver' => function(Cart $cart) {
                    $id = $this->dataSource['shipping_address_id'] ?? null;
                    $conn = _mysql();
                    if(!$id || !$conn->getTable('cart_address')->load($id))
                        $this->error = "Shipping address can not be empty";

                    return  $id;
                }
            ],
            'shipping_method' => [
                'resolver' => function(Cart $cart) {
                    $method = dispatch_event('apply_shipping_method', [$this, $this->dataSource]);
                    if(!$method)
                        $this->error = "Shipping method can not be empty";

                    return $method;
                },
                'dependencies' => ['sub_total']
            ],
            'shipping_method_name' => [
                'resolver' => function(Cart $cart) {
                    return $this->dataSource['shipping_method_name'] ?? $this->dataSource['shipping_method_name'] ?? null;
                }
            ],
            'shipping_note' => [
                'resolver' => function(Cart $cart) {
                    return $this->dataSource['shipping_note'] ?? $this->dataSource['shipping_note'] ?? null;
                }
            ],
            'billing_address_id' => [
                'resolver' => function(Cart $cart) {
                    $id = $this->dataSource['billing_address_id'] ?? null;
                    $conn = _mysql();
                    if(!$id || !$conn->getTable('cart_address')->load($id))
                        return null;
                    return  $id;
                }
            ],
            'payment_method' => [
                'resolver' => function(Cart $cart) {
                    $method = dispatch_event('apply_payment_method', [$this, $this->dataSource]);
                    if(!$method)
                        $this->error = "Payment method can not be empty";

                    return $method;
                },
                'dependencies' => ['sub_total']
            ],
            'payment_method_name' => [
                'resolver' => function(Cart $cart) {
                    return $this->dataSource['payment_method_name'] ?? $this->dataSource['payment_method_name'] ?? null;
                }
            ],
            'items' => [
                'resolver' => function(Cart $cart) {
                    if(isset($this->dataSource['items']))
                        return $this->dataSource['items'];
                    $items = _mysql()->getTable('cart_item')->where('cart_id', '=', $this->getData('cart_id'))->fetchAllAssoc();
                    $is = [];
                    foreach ($items as $item) {
                        $i = new Item($cart, $item);
                        $is[$i->getId()] = $i;
                    }

                    return $is;
                },
                'dependencies' => ['cart_id', 'customer_group_id', 'shipping_address_id'],
            ]
        ];
        dispatch_event("register_cart_field", [&$fields]);

        $this->fields = $this->sortFields($fields);

        return $this;
    }

    public function addItem(array $itemData) {
        $item = new Item($this, $itemData);
        if($item->getError())
            return new RejectedPromise($item);

        $items = $this->getData('items') ?? [];
        $items[$item->getId()] = $item;
        $promise = $this->setData('items', $items);

        if($promise->getState() == 'fulfilled' && $this->getItem($item->getId()))
            return new FulfilledPromise($item);
        else
            return new RejectedPromise($item->getError());
    }

    public function removeItem($id)
    {
        $item = $this->itemFactory->removeItem($id);

        $promise = new \GuzzleHttp\Promise\Promise(function() use (&$promise, $item) {
            $promise->resolve($item);
        });
        $this->promises[] = $promise;

        return $promise;
    }

    public function getField($field) {
        return $this->fields[$field] ?? null;
    }

    public function addField($field, callable $resolver = null, $dependencies = [])
    {
        $this->fields[$field] = [
            'resolve' => $resolver,
            'dependencies' => $dependencies
        ];

        return $this;
    }

    public function removeField($field)
    {
        if(isset($this->fields[$field]))
            unset($this->fields[$field]);

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return PromiseInterface
     */

    public function setData($key, $value)
    {
        if($this->isOrdered != false)
            return new RejectedPromise("Cart is disabled");

        if($this->isRunning == true)
            return new RejectedPromise("Can not set value when resolves are running");

        $this->dataSource[$key] = $value;

        if(isset($this->fields[$key]) and !empty($this->fields[$key]['dependencies'])) {
            $this->dataSource[$key] = $value;
            $promise = new \GuzzleHttp\Promise\Promise(function() use (&$promise, $key, $value) {
                if($this->getData($key) == $value) {
                    $promise->resolve($value);
                } else
                    $promise->reject("Can not change {$key} field");
            });
            $this->setDataPromise = $promise;
            $this->onChange(null);

            return $promise;
        } else {
            $previous = $this->fields[$key]['value'] ?? null;
            $resolver = \Closure::bind($this->fields[$key]["resolver"], $this);
            $_value = $resolver($this);

            if($value != $_value) {
                return new RejectedPromise("Field resolver returns different value");
            } else if($previous == $_value) {
                return new FulfilledPromise($value);
            } else {
                $this->fields[$key]['value'] = $value;
                $this->dataSource[$key] = $value;
                $this->onChange($key);
                return new FulfilledPromise($value);
            }
        }
    }

    protected function onChange($key)
    {
        if($this->isOrdered != false)
            return null;

        if($this->isRunning == false) {
            $this->isRunning = true;
            $this->error = null;
            foreach ($this->fields as $k=>$value) {
                if($k != $key) {
                    $this->fields[$k]['value'] = $value["resolver"]($this, $this->dataSource);
                }
            }
            $this->isRunning = false;
            if($this->setDataPromise)
                $this->setDataPromise->wait();
            dispatch_event('cart_updated', [$this, $key]);
        }
    }

    public function getData($key)
    {
        return $this->fields[$key]['value'] ?? null;
    }

    public function toArray()
    {
        $data = [];
        foreach ($this->fields as $key => $field)
            $data[$key] = $field['value'] ?? null;

        return $data;
    }

    /**
     * @return Item[]
     */
    public function getItems()
    {
        return $this->fields['items']['value'] ?? [];
    }

    /**
     * @return Item
     */
    public function getItem($id)
    {
        return $this->getData("items")[$id] ?? null;
    }

    public function isEmpty()
    {
        return empty($this->getItems());
    }

    /**
     * @return Promise[]
     */
    public function getPromises(): array
    {
        return $this->promises;
    }

    public function createOrderSync()
    {
        $conn = _mysql();
        $shippingAddress = $conn->getTable('cart_address')->load($this->getData('shipping_address_id'));
        if(!$shippingAddress)
            throw new \Exception("Please provide shipping address");

        if($this->error)
            throw new \Exception($this->error);

        $items = $this->getItems();
        foreach ($items as $item)
            if($item->getError())
                throw new \Exception("There is an error in shopping cart item");

        // Start saving order
        $customerId = $this->getData('customer_id');
//        if($customerId) {
//            $customer = $this->processor->getTable('customer')->load($customerId);
//            if(!$customer)
//                throw new \Exception("Customer does not exist");
//            $customerData = [
//                'customer_email' => $customer['email'],
//                'customer_full_name' => $customer['full_name'],
//            ];
//        } else {
//            $customerData = [
//                'customer_email' => null,
//                'customer_full_name' => null
//            ];
//        }
        $autoIncrement = $conn
            ->executeQuery("SELECT `AUTO_INCREMENT` FROM  INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :database AND TABLE_NAME   = :table", ['database'=> DB_DATABASE, 'table'=>'order'])
            ->fetch(\PDO::FETCH_ASSOC);

        $orderData = array_merge($this->toArray(), [
            'order_number' =>10000 + (int)$autoIncrement['AUTO_INCREMENT'],
            'shipment_status' => 'pending',
            'payment_status' => 'pending'
        ]);

        dispatch_event("filter_order_data", [&$orderData]);

        $conn->startTransaction();
        try {
            // Order address
            $shippingAddressId = $conn->getTable('order_address')
                ->insert($shippingAddress);
            $billingAddress = $conn->getTable('cart_address')->load($this->getData('billing_address_id'));
            if(!$billingAddress)
            $billingAddress = $shippingAddress;
            $conn->getTable('order_address')
                ->insert($billingAddress);
            $billingAddressId = $conn->getLastID();

            $conn->getTable('order')
                ->insert(array_merge($orderData, [
                    'shipping_address_id' => $shippingAddressId,
                    'billing_address_id' => $billingAddressId
                ]));
            $orderId = $conn->getLastID();
            $items = $this->getItems();
            foreach ($items as $item) {
                $itemData = array_merge($item->toArray(), ['order_item_order_id' => $orderId]);
                dispatch_event("filter_order_data", [&$itemData]);

                $conn->getTable('order_item')->insert($itemData);
            }

            // Order activities
            $conn->getTable('order_activity')
                ->insert([
                    'order_activity_order_id' => $orderId,
                    'comment' => 'Order created',
                    'customer_notified' => 0 //TODO: check config of SendGrid
                ]);
            $this->isOrdered = $orderId;

            // Disable cart
            $conn->getTable('cart')
                ->where('cart_id', '=', $this->getData('cart_id'))
                ->update(['status'=>0]);
            $conn->commit();

            return $orderId;
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function createOrder()
    {
        if($this->isOrdered != false) {
            return new FulfilledPromise($this->isOrdered);
        }

        $promise = new \GuzzleHttp\Promise\Promise(function() use (&$promise) {
            $orderId = $this->createOrderSync();
            $promise->resolve($orderId);
        });
        
        $this->promises[] = $promise;

        return $promise;
    }

    public function destroy()
    {
        $this->dataSource = [];
        $this->itemFactory->setCart($this);
        $this->setData('cart_id', null);
    }

    protected function sortFields(array $fields)
    {
        $sorter = new ArraySort();
        foreach ($fields as $key=>$value) {
            $sorter->add($key, $value['dependencies'] ?? []);
        }
        $sorted = $sorter->doSort();

        $result = [];
        foreach ($sorted as $key=>$value)
            $result[$value] = $fields[$value];

        return $result;
    }

    /**
     * @return ItemFactory
     */
    public function getItemFactory(): ItemFactory
    {
        return $this->itemFactory;
    }
}