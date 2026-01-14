<?php
namespace GardenLawn\TransEu\Model\Data;

use Magento\Framework\DataObject;
use GardenLawn\TransEu\Api\Data\PricePredictionRequestInterface;

class PricePredictionRequest extends DataObject implements PricePredictionRequestInterface
{
    /**
     * @inheritDoc
     */
    public function getCompanyId()
    {
        return $this->getData(self::COMPANY_ID);
    }

    /**
     * @inheritDoc
     */
    public function setCompanyId($companyId)
    {
        return $this->setData(self::COMPANY_ID, $companyId);
    }

    /**
     * @inheritDoc
     */
    public function getUserId()
    {
        return $this->getData(self::USER_ID);
    }

    /**
     * @inheritDoc
     */
    public function setUserId($userId)
    {
        return $this->setData(self::USER_ID, $userId);
    }

    /**
     * @inheritDoc
     */
    public function getDistance()
    {
        return $this->getData(self::DISTANCE);
    }

    /**
     * @inheritDoc
     */
    public function setDistance($distance)
    {
        return $this->setData(self::DISTANCE, $distance);
    }

    /**
     * @inheritDoc
     */
    public function getCurrency()
    {
        return $this->getData(self::CURRENCY);
    }

    /**
     * @inheritDoc
     */
    public function setCurrency($currency)
    {
        return $this->setData(self::CURRENCY, $currency);
    }

    /**
     * @inheritDoc
     */
    public function getSpots()
    {
        return $this->getData(self::SPOTS);
    }

    /**
     * @inheritDoc
     */
    public function setSpots(array $spots)
    {
        return $this->setData(self::SPOTS, $spots);
    }

    /**
     * @inheritDoc
     */
    public function getVehicleRequirements()
    {
        return $this->getData(self::VEHICLE_REQUIREMENTS);
    }

    /**
     * @inheritDoc
     */
    public function setVehicleRequirements(array $vehicleRequirements)
    {
        return $this->setData(self::VEHICLE_REQUIREMENTS, $vehicleRequirements);
    }

    /**
     * @inheritDoc
     */
    public function toArray(array $keys = [])
    {
        // Ensure default structure if not set
        $data = parent::toArray($keys);

        // Add static fields required by API
        $data['is_corporate_exchange'] = false;
        $data['is_private_exchange'] = false;
        $data['temperature'] = ["min" => "", "max" => ""];

        return $data;
    }
}
