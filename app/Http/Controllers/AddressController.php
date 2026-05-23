<?php

namespace App\Http\Controllers;

use App\Http\Requests\Address\StoreAddressRequest;
use App\Http\Requests\Address\UpdateAddressRequest;
use App\Http\Resources\Profile\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function getProvinces()
    {
        return success(getProvince());
    }

    public function getCities($provinceID = null)
    {
        return success(getCities($provinceID));
    }

    public function index(): JsonResponse
    {
        $user = Auth::user();
        $addresses = $user->addresses()->latest()->get();

        return success(AddressResource::collection($addresses), __('address.list_success'));
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $address = DB::transaction(function () use ($user, $data) {

            if (! empty($data[Address::IS_DEFAULT])) {
                $user->addresses()->update([Address::IS_DEFAULT => false]);
            }

            $address = $user->addresses()->create([
                Address::TITLE => $data[Address::TITLE] ?? null,
                Address::PROVINCE_ID => $data[Address::PROVINCE_ID],
                Address::CITY_ID => $data[Address::CITY_ID],
                Address::POSTAL_ADDRESS => $data[Address::POSTAL_ADDRESS],
                Address::BUILDING_NUMBER => $data[Address::BUILDING_NUMBER] ?? null,
                Address::UNIT => $data[Address::UNIT] ?? null,
                Address::POSTAL_CODE => $data[Address::POSTAL_CODE] ?? null,
                Address::RECEIVER_NAME => $data[Address::RECEIVER_NAME],
                Address::RECEIVER_MOBILE => $data[Address::RECEIVER_MOBILE],
                Address::IS_DEFAULT => $data[Address::IS_DEFAULT] ?? false,
            ]);

            if ($user->addresses()->count() === 1) {
                $address->update([Address::IS_DEFAULT => true]);
            }

            return $address;
        });

        return success(new AddressResource($address), __('address.store_success'), 201);
    }

    public function update(UpdateAddressRequest $request, Address $address): JsonResponse
    {

        if ($address->{Address::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $data = $request->validated();

        DB::transaction(function () use ($address, $data) {

            if (! empty($data[Address::IS_DEFAULT]) && $data[Address::IS_DEFAULT] == true) {

                Auth::user()->addresses()
                    ->where(Address::ID, '!=', $address->id)
                    ->update([Address::IS_DEFAULT => false]);
            }

            $address->update($data);
        });

        return success(new AddressResource($address), __('address.update_success'));
    }

    public function destroy(Address $address): JsonResponse
    {
        if ($address->{Address::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($address->{Address::IS_DEFAULT}) {
            return error(__('address.delete_default_denied'), [], 400);
        }

        $address->delete();

        return success([], __('address.deleted_success'));
    }

    public function setDefault(Address $address): JsonResponse
    {
        if ($address->{Address::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        DB::transaction(function () use ($address) {
            Auth::user()->addresses()->where(Address::ID, '!=', $address->id)
                ->update([Address::IS_DEFAULT => false]);
            $address->update([Address::IS_DEFAULT => true]);
        });

        return success(new AddressResource($address), __('address.set_default_success'));
    }
}
