<?php

use Bolt_Boltpay_Controller_Interface as RESPONSE_CODE;
use Bolt_Boltpay_TestHelper as TestHelper;

/**
 * Class Bolt_Boltpay_OrderCreationExceptionTest
 *
 * @coversDefaultClass Bolt_Boltpay_OrderCreationException
 */
class Bolt_Boltpay_OrderCreationExceptionTest extends PHPUnit_Framework_TestCase
{
    /**
     * @test
     * JSON response for general error with default parameters
     *
     * @covers ::__construct
     */
    public function __construct_withGeneralException_setsCorrectJsonAndHttpCode()
    {
        $reason = 'test error';
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC,
            [$reason]
        );

        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertNotEmpty($exception->status);
        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR);
        $this->assertNotEmpty($exception->error[0]->data[0]->reason);

        $this->assertEquals(Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR, $exception->error[0]->code);
        $this->assertEquals($reason, $exception->error[0]->data[0]->reason);

        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct data for existing order exception
     *
     * @covers ::__construct
     */
    public function __construct_withExistingCartException_setsCorrectJsonAndHttpCode()
    {
        $dataValues = ['id_123', 'pending'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS,
            Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS_TMPL,
            $dataValues);
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->display_id);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->order_status);

        $this->assertEquals(RESPONSE_CODE::HTTP_CONFLICT, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct data for various cart exceptions
     *
     * @covers ::__construct
     */
    public function __construct_withCartException_setsCorrectJsonAndHttpCode()
    {
        // Empty cart
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_EMPTY
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('Cart is empty', $exception->error[0]->data[0]->reason);
        $this->assertEquals(RESPONSE_CODE::HTTP_GONE, $boltOrderCreationException->getHttpCode());

        // Expired cart
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_EXPIRED
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('Cart has expired', $exception->error[0]->data[0]->reason);
        $this->assertEquals(RESPONSE_CODE::HTTP_GONE, $boltOrderCreationException->getHttpCode());

        // Cart does not exist
        $dataValues = ['cart_reference_001'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_NOT_FOUND,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('Cart does not exist with reference', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->reference);
        $this->assertEquals(RESPONSE_CODE::HTTP_NOT_FOUND, $boltOrderCreationException->getHttpCode());

        // Cart is not purchasable
        $dataValues = [905];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_NOT_PURCHASABLE,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('The product is not purchasable', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->product_id);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());

        // Cart grand total has changed
        $dataValues = [100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_GRAND_TOTAL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('Grand total has changed', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->old_value);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());

        // Cart discount coupon total has changed
        $dataValues = ['BOLT10OFF', 100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY,
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY_TMPL_COUPON_TOTAL_CHANGED,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY);
        $this->assertEquals('Discount amount has changed', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->discount_code);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->old_value);
        $this->assertEquals($dataValues[2], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());

        // Cart discount total has changed
        $dataValues = [100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY,
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY_TMPL_TOTAL_CHANGED,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY);
        $this->assertEquals('Discount total has changed', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->old_value);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());

        // Chart tax has changed
        $dataValues = [100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_TAX,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED);
        $this->assertEquals('Tax amount has changed', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->old_value);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct data for item price has been updated exception
     *
     * @covers ::__construct
     */
    public function __construct_withItemPriceException_setsCorrectJsonAndHttpCode()
    {
        $dataValues = [905, 100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED_TMPL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->product_id);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->old_price);
        $this->assertEquals($dataValues[2], $exception->error[0]->data[0]->new_price);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct data for out of inventory exception
     *
     * @covers ::__construct
     */
    public function __construct_withCartInventoryException_setsCorrectJsonAndHttpCode()
    {
        $dataValues = [905, 100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_OUT_OF_INVENTORY,
            Bolt_Boltpay_OrderCreationException::E_BOLT_OUT_OF_INVENTORY_TMPL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_OUT_OF_INVENTORY);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->product_id);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->available_quantity);
        $this->assertEquals($dataValues[2], $exception->error[0]->data[0]->needed_quantity);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct exception data when discount can not be applied
     *
     * @covers ::__construct
     */
    public function __construct_withDiscountCanNotBeAppliedException_setsCorrectJsonAndHttpCode()
    {
        // Generic exception
        $dataValues = ['Discount code too cool to be used', 'FREE_BEER_FOR_LIFE'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY,
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY_TMPL_GENERIC,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->discount_code);

        // Discount code expired exception
        $dataValues = ['FREE_BEER_FOR_1_SEC'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY,
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY_TMPL_EXPIRED,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY);
        $this->assertEquals('This coupon has expired', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->discount_code);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct exception data when discount code is invalid
     *
     * @covers ::__construct
     */
    public function __construct_withInvalidDiscountCodeException_setsCorrectJsonAndHttpCode()
    {
        $dataValues = ['GIVE_ME_FREE'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_DOES_NOT_EXIST,
            Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_DOES_NOT_EXIST_TMPL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_DOES_NOT_EXIST);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->discount_code);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * for correct exception when shipping price or tax are changed
     *
     * @covers ::__construct
     */
    public function __construct_withShippingPriceOrTaxChangedException_setsCorrectJsonAndHttpCode()
    {
        $dataValues = [100, 150];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED_TMPL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED);
        $this->assertEquals('Shipping total has changed', $exception->error[0]->data[0]->reason);
        $this->assertEquals($dataValues[0], $exception->error[0]->data[0]->old_value);
        $this->assertEquals($dataValues[1], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * In case when we pass string data instead of expected numbers, values in the response will be set to zero.
     *
     * @covers ::__construct
     */
    public function __construct_withIncorrectData_setsValuesInResponseToZero()
    {
        $dataValues = ['hundred', 'hundred and fifty'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED,
            Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED_TMPL,
            $dataValues
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_SHIPPING_PRICE_HAS_BEEN_UPDATED);
        $this->assertEquals('Shipping total has changed', $exception->error[0]->data[0]->reason);
        $this->assertNotEquals($dataValues[0], $exception->error[0]->data[0]->old_value);
        $this->assertNotEquals($dataValues[1], $exception->error[0]->data[0]->new_value);
        $this->assertEquals(0, $exception->error[0]->data[0]->old_value);
        $this->assertEquals(0, $exception->error[0]->data[0]->new_value);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * that correct JSON and HTTP response code is set when throwing exception with
     * @see Bolt_Boltpay_OrderCreationException::E_BOLT_MINIMUM_PRICE_NOT_MET code
     *
     * @covers ::__construct
     */
    public function __construct_withMinimumPriceNotMetException_setsCorrectJsonAndHttpCode()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_MINIMUM_PRICE_NOT_MET,
            Bolt_Boltpay_OrderCreationException::E_BOLT_MINIMUM_PRICE_NOT_MET_TMPL,
            array(
                '$123.45'
            )
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_MINIMUM_PRICE_NOT_MET);
        $this->assertEquals('The minimum order amount of $123.45 has not been met', $exception->error[0]->data[0]->reason);
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * if OrderCreationException is keeping a reference to previous error
     *
     * @covers ::__construct
     */
    public function __construct_withExceptionWithPreviousException_setsPreviousExceptionInResponse()
    {
        $reason = 'test with previous error';
        $previousException = new Exception('This is previous error');
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC,
            [$reason],
            $reason,
            $previousException
        );

        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertNotEmpty($exception->status);
        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR);
        $this->assertNotEmpty($exception->error[0]->data[0]->reason);

        $this->assertEquals(Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR, $exception->error[0]->code);
        $this->assertEquals($reason, $exception->error[0]->data[0]->reason);
        $this->assertEquals($previousException, $boltOrderCreationException->getPrevious());
        $this->assertEquals('This is previous error', $boltOrderCreationException->getPrevious()->getMessage());
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * if special characters are escaped in JSON response
     *
     * @covers ::__construct
     */
    public function __construct_withEscapedSpecialCharacters_setsCorrectJsonAndHttpCode()
    {
        $reason = 'test "error" \path\example';
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC,
            [$reason]
        );
        $exceptionJson = $boltOrderCreationException->getJson();
        $exception = json_decode($exceptionJson);

        $this->assertExceptionProperties($exception, Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR);
        $this->assertEquals($reason, $exception->error[0]->data[0]->reason);

        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * If non-registered code is passed to a constructor it should generate generic exception.
     *
     * @covers ::__construct
     */
    public function __construct_withNonRegisteredErrorCode_shouldGenerateGenericException()
    {

        $errorMessage = 'test non-existing code';
        $templateData = ['this error is from the template'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            12345,
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC,
            $templateData,
            $errorMessage
        );

        Bolt_Boltpay_TestHelper::callNonPublicFunction(
            $boltOrderCreationException,
            'createJson',
            [
                12345,
                Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC,
                $templateData
            ]
        );

        $this->assertContains(
            "Supplied error:",
            $boltOrderCreationException->getJson()
        );

        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * HTTP response code for general exception with default parameters
     *
     * @covers ::__construct
     * @covers ::getHttpCode
     */
    public function __construct_withDefaultParameters_shouldSetResponseCodeTo422()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();
        $this->assertEquals(RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * HTTP response code for general exception with invalid HMAC header
     *
     * @covers ::__construct
     * @covers ::getHttpCode
     */
    public function __construct_withInvalidHMACHeader_shouldSetResponseCodeTo401()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
            Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_HMAC
        );
        $this->assertEquals(RESPONSE_CODE::HTTP_UNAUTHORIZED, $boltOrderCreationException->getHttpCode());
    }

    /**
     * @test
     * that selectHttpCode should return HTTP conflict code when the input parameter code indicates an order already
     * exists
     *
     * @covers ::selectHttpCode
     */
    public function selectHttpCode_whenOrderAlreadyExists_returns409()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();

        $this->assertEquals(
            RESPONSE_CODE::HTTP_CONFLICT,
            $boltOrderCreationException->selectHttpCode(
                Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS,
                ''
            )
        );
    }

    /**
     * @test
     * that selectHttpCode should return HTTP not found code when input parameter code indicates an cart has expired
     * and template not found
     *
     * @covers ::selectHttpCode
     */
    public function selectHttpCode_whenCartIsNotFound_returns404()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();

        $this->assertEquals(
            RESPONSE_CODE::HTTP_NOT_FOUND,
            $boltOrderCreationException->selectHttpCode(
                Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
                Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_NOT_FOUND
            )
        );
    }

    /**
     * @test
     * that selectHttpCode should return HTTP gone code when input parameter code indicates an cart has expired and
     * and template expired
     *
     * @covers ::selectHttpCode
     */
    public function selectHttpCode_whenCartHasExpired_returns410()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();

        $this->assertEquals(
            RESPONSE_CODE::HTTP_GONE,
            $boltOrderCreationException->selectHttpCode(
                Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED,
                Bolt_Boltpay_OrderCreationException::E_BOLT_CART_HAS_EXPIRED_TMPL_EXPIRED
            )
        );
    }

    /**
     * @test
     * that selectHttpCode should return HTTP gone code when input parameter code indicates an cart has expired and
     * template has expired
     *
     * @covers ::selectHttpCode
     */
    public function selectHttpCode_whenGeneralErrorOccur_returns401()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();

        $this->assertEquals(
            RESPONSE_CODE::HTTP_UNAUTHORIZED,
            $boltOrderCreationException->selectHttpCode(
                Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR,
                Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_HMAC
            )
        );
    }

    /**
     * @test
     * that selectHttpCode should return HTTP unprocessable entity when input parameter code indicates an discount
     * cannot apply
     *
     * @covers ::selectHttpCode
     */
    public function selectHttpCode_whenDiscountCannotApply_422()
    {
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException();

        $this->assertEquals(
            RESPONSE_CODE::HTTP_UNPROCESSABLE_ENTITY,
            $boltOrderCreationException->selectHttpCode(
                Bolt_Boltpay_OrderCreationException::E_BOLT_DISCOUNT_CANNOT_APPLY,
                ''
            )
        );
    }

    /**
     * @test
     * that createJson returns json data response
     * 
     * @covers ::createJson
     */
    public function createJson_always_returnsJsonString()
    {
        $actualResult = TestHelper::callNonPublicFunction(
            new Bolt_Boltpay_OrderCreationException(),
            'createJson',
            [RESPONSE_CODE::HTTP_GONE, Bolt_Boltpay_OrderCreationException::E_BOLT_GENERAL_ERROR_TMPL_GENERIC]
        );

        $this->assertJson($actualResult);
    }
    
    /**
     * @test
     * that getJson returns json response
     *
     * @covers ::getJson
     */
    public function getJson_always_returnsJsonString()
    {
        $dataValues = ['id_123', 'pending'];
        $boltOrderCreationException = new Bolt_Boltpay_OrderCreationException(
            Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS,
            Bolt_Boltpay_OrderCreationException::E_BOLT_ORDER_ALREADY_EXISTS_TMPL,
            $dataValues);
        
        $this->assertJson($boltOrderCreationException->getJson());
    }

    /**
     * Helper function for asserting that all required params exist in the exception instance
     *
     * @param Throwable $exception The exception instance we are testing
     * @param int       $code      The Bolt int code that classifies the exception
     */
    private function assertExceptionProperties($exception, $code)
    {
        $this->assertNotEmpty($exception->error);
        $this->assertEquals($code, $exception->error[0]->code);
        $this->assertNotEmpty($exception->error[0]->data);
        $this->assertEquals('failure', $exception->status);
    }
}
