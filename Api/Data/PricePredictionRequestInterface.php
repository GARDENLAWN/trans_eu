<?php
namespace GardenLawn\TransEu\Api\Data;

interface PricePredictionRequestInterface
{
    const COMPANY_ID = 'company_id';
    const USER_ID = 'user_id';
    const DISTANCE = 'distance';
    const CURRENCY = 'currency';
    const SPOTS = 'spots';
    const VEHICLE_REQUIREMENTS = 'vehicle_requirements';

    /**
     * @return int
     */
    public function getCompanyId();

    /**
     * @param int $companyId
     * @return $this
     */
    public function setCompanyId($companyId);

    /**
     * @return int
     */
    public function getUserId();

    /**
     * @param int $userId
     * @return $this
     */
    public function setUserId($userId);

    /**
     * @return float
     */
    public function getDistance();

    /**
     * @param float $distance
     * @return $this
     */
    public function setDistance($distance);

    /**
     * @return string
     */
    public function getCurrency();

    /**
     * @param string $currency
     * @return $this
     */
    public function setCurrency($currency);

    /**
     * @return array
     */
    public function getSpots();

    /**
     * @param array $spots
     * @return $this
     */
    public function setSpots(array $spots);

    /**
     * @return array
     */
    public function getVehicleRequirements();

    /**
     * @param array $vehicleRequirements
     * @return $this
     */
    public function setVehicleRequirements(array $vehicleRequirements);

    /**
     * Convert object to array for API payload
     * @return array
     */
    public function toArray();
}
