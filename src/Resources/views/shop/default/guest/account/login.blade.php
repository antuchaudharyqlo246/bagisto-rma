<x-shop::layouts
    :has-header="false"
    :has-feature="false"
    :has-footer="false"
>

{{-- Title of the page --}}
    <x-slot:title>
        @lang('rma::app.shop.guest-users.title')
    </x-slot>

    <div class="container mt-20 max-1180:px-[20px]">
        {{-- Company Logo --}}
        <div class="flex gap-x-[54px] items-center max-[1180px]:gap-x-[35px]">
            <a
                href="{{ route('shop.home.index') }}"
                class="m-[0_auto_20px_auto]"
                aria-label="Bagisto "
            >
                <img
                    src="{{ bagisto_asset('images/logo.svg') }}"
                    alt="Bagisto "
                    width="131"
                    height="29"
                >
            </a>
        </div>
        <div class="auth-content">
            <div
                class="w-full max-w-[870px] m-auto px-[90px] py-[60px] border border-[#E9E9E9] rounded-[12px] max-md:px-[30px] max-md:py-[30px]"
            >

            <x-shop::form
                :action="route('rma.guest.logincreate')"
                method="POST"
                enctype="multipart/form-data"
            >

                <div class="flex gap-[16px] justify-between items-center max-sm:flex-wrap">
                    <h2 class="text-[25px] font-medium">
                        @lang('rma::app.shop.guest-users.heading')
                    </h2>
                </div>

                <p class="mt-[15px] text-[#6E6E6E] text-[20px] max-sm:text-[16px]">
                    @lang('shop::app.customers.login-form.form-login')
                </p>

                <x-shop::form.control-group class="mb-4">
                    <x-shop::form.control-group.label class="required">
                        @lang('rma::app.shop.guest-users.order-id')
                    </x-shop::form.control-group.label>

                    <x-shop::form.control-group.control
                        type="text"
                        name="order_id"
                        value="{{ old('order_id') }}"
                        rules="required|integer"
                        :label="trans('rma::app.shop.guest-users.order-id')"
                        :placeholder="trans('rma::app.shop.guest-users.order-id')"
                    >
                    </x-shop::form.control-group.control>

                    <x-shop::form.control-group.error
                        control-name="order_id"
                    >
                    </x-shop::form.control-group.error>
                </x-shop::form.control-group>

                <x-shop::form.control-group class="mb-4">
                    <x-shop::form.control-group.label class="required">
                        @lang('rma::app.shop.guest-users.email')
                    </x-shop::form.control-group.label>

                    <x-shop::form.control-group.control
                        type="email"
                        name="email"
                        id="email"
                        value="{{ old('email') }}"
                        rules="required|email"
                        :label="trans('rma::app.shop.guest-users.email')"
                        placeholder="email@example.com"
                    >
                    </x-shop::form.control-group.control>

                    <x-shop::form.control-group.error
                        control-name="email"
                    >
                    </x-shop::form.control-group.error>
                </x-shop::form.control-group>

                <button
                    type="submit"
                    class="primary-button"
                >
                    @lang('rma::app.shop.guest-users.button-text')
                </button>
            </x-shop::form>

            <p class="mt-[20px] text-[#6E6E6E] font-medium">
                @lang('shop::app.customers.login-form.new-customer')

                <a
                    class="text-navyBlue"
                    href="{{ route('shop.customers.register.index') }}"
                >
                    @lang('shop::app.customers.login-form.create-your-account')
                </a>
            </p>
            </div>

            <p class="mt-[30px] mb-[15px] text-center text-[#6E6E6E] text-xs">
            @lang('shop::app.customers.login-form.footer', ['current_year'=> date('Y') ])
            </p>

        </div>
    </div>
</x-shop::layouts>
