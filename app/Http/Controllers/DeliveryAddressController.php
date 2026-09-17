<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliveryAddressController extends Controller
{
    /**
     * Add a delivery address for a user
     */
    public function addDeliveryAddress(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer|exists:users,id',
                'name'    => 'required|string|max:255',
                'mobile'  => 'required|string|max:20',
                'address' => 'required|string',
                'district'=> 'required|string|max:255',
                'division'=> 'required|string|max:255',
                'area'    => 'required|string|max:255',
                'house'   => 'nullable|string|max:255',
                'flat'    => 'nullable|string|max:255',
                'lat'     => 'nullable|numeric',
                'lon'     => 'nullable|numeric',
                'note'    => 'nullable|string',
                'status'  => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->failed('Validation failed', $validator->errors(), 422);
            }

            $address = DeliveryAddress::create($validator->validated());

            return $this->success('Address added successfully', $address, 201);
        } catch (\Exception $e) {
            return $this->failed('Could not add address', $e->getMessage(), 500);
        }
    }

    /**
     * Get all addresses for a user
     */
    public function getAddressByUser($userId)
    {
        try {
            $addresses = DeliveryAddress::where('user_id', $userId)->with(['district', 'division'])->get();

            return $this->success('Addresses retrieved', $addresses);
        } catch (\Exception $e) {
            return $this->failed('Could not retrieve addresses', $e->getMessage(), 500);
        }
    }

    /**
     * Delete an address
     */
    public function deleteAddress($id)
    {
        try {
            $address = DeliveryAddress::find($id);

            if (! $address) {
                return $this->failed('Address not found', null, 404);
            }

            $address->delete();

            return $this->success('Address deleted successfully');
        } catch (\Exception $e) {
            return $this->failed('Could not delete address', $e->getMessage(), 500);
        }
    }

    /**
     * Mark an address as inactive
     */
    public function inactiveAddress($id)
    {
        try {
            $address = DeliveryAddress::find($id);

            if (! $address) {
                return $this->failed('Address not found', null, 404);
            }

            $address->status = false;
            $address->save();

            return $this->success('Address set to inactive successfully', $address);
        } catch (\Exception $e) {
            return $this->failed('Could not update address status', $e->getMessage(), 500);
        }
    }

    /**
     * Update an address
     */
    public function updateAddress(Request $request, $id)
    {
        try {
            $address = DeliveryAddress::find($id);

            if (! $address) {
                return $this->failed('Address not found', null, 404);
            }

            $validator = Validator::make($request->all(), [
                'name'    => 'sometimes|required|string|max:255',
                'mobile'  => 'sometimes|required|string|max:20',
                'address' => 'sometimes|required|string',
                'district'=> 'sometimes|required|string|max:255',
                'area'    => 'sometimes|required|string|max:255',
                'house'   => 'nullable|string|max:255',
                'flat'    => 'nullable|string|max:255',
                'lat'     => 'nullable|numeric',
                'lon'     => 'nullable|numeric',
                'note'    => 'nullable|string',
                'status'  => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->failed('Validation failed', $validator->errors(), 422);
            }

            $address->update($validator->validated());

            return $this->success('Address updated successfully', $address);
        } catch (\Exception $e) {
            return $this->failed('Could not update address', $e->getMessage(), 500);
        }
    }

    /**
     * Get all divisions
     */
    public function getDivisions()
    {
        try {
            $divisions = Division::all();

            return $this->success('Divisions retrieved', $divisions);
        } catch (\Exception $e) {
            return $this->failed('Could not retrieve divisions', $e->getMessage(), 500);
        }
    }

    /**
     * Get districts by division
     */
    public function getDistrictsByDivision($divisionId)
    {
        try {
            $districts = District::where('division_id', $divisionId)->get();

            return $this->success('Districts retrieved', $districts);
        } catch (\Exception $e) {
            return $this->failed('Could not retrieve districts', $e->getMessage(), 500);
        }
    }

    /**
     * Get upazilas by district
     */
    public function getUpazilasByDistrict($districtId)
    {
        try {
            $upazilas = Upazila::where('district_id', $districtId)->get();

            return $this->success('Upazilas retrieved', $upazilas);
        } catch (\Exception $e) {
            return $this->failed('Could not retrieve upazilas', $e->getMessage(), 500);
        }
    }

    // Response helpers
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }
}


