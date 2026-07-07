<?php
	/*
	Plugin Name: درگاه دولتی بانک ملی ووکامرس
	Plugin URI: https://ravandsoft.com
	Description: درگاه بانک ملی برای ووکامرس با قابلیت پرداخت شناسه‌دار
	Version: 1.3.0
	Author: http://ravandsoft.com
	Author URI: http://ravandsoftc.com
	 */

	if (!defined('ABSPATH')) {
		exit;
	}

	add_action('plugins_loaded', 'init_melli_woocommerce', 0);

	add_filter('woocommerce_currencies', 'mw_add_currency');

	function mw_add_currency($currencies) {
		$currencies['IRR'] = __('ریال', 'woocommerce');
		$currencies['IRT'] = __('تومان', 'woocommerce');
		return $currencies;
	}

	add_filter('woocommerce_currency_symbol', 'mw_add_currency_symbol', 10, 2);

	function mw_add_currency_symbol($currency_symbol, $currency) {
		$currency_symbol = ($currency == 'IRR') ? 'ریال' : $currency_symbol;
		$currency_symbol = ($currency == 'IRT') ? 'تومان' : $currency_symbol;
		return $currency_symbol;
	}

	add_action('show_user_profile', 'vendor_payment_id_field');
	add_action('edit_user_profile', 'vendor_payment_id_field');

	function vendor_payment_id_field($user) {

		if (!in_array('vendor', (array) $user->roles)) {
			return;
		}
		?>
		<h3>اطلاعات پرداخت دانشکده</h3>
		<table class="form-table">
			<tr>
				<th><label for="payment_id">شناسه پرداخت</label></th>
				<td>
					<input type="text"
						name="payment_id"
						id="payment_id"
						value="<?php echo esc_attr(get_user_meta($user->ID, 'payment_id', true)); ?>"
						class="regular-text"/>
					<p class="description">
						شناسه پرداخت ارسال‌شده به درگاه
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	add_action('personal_options_update', 'save_vendor_payment_id');
	add_action('edit_user_profile_update', 'save_vendor_payment_id');

	function save_vendor_payment_id($user_id) {

		if (!current_user_can('edit_user', $user_id)) {
			return;
		}

		$user = get_userdata($user_id);

		if (!in_array('vendor', (array) $user->roles)) {
			return;
		}

		if (isset($_POST['payment_id'])) {
			update_user_meta(
				$user_id,
				'payment_id',
				sanitize_text_field($_POST['payment_id'])
			);
		}
	}

	function get_product_vendor_payment_id(int $product_id): ?string {

		$vendor_id = (int) get_post_field('post_author', $product_id);

		if (!$vendor_id) {
			return null;
		}

		$payment_id = get_user_meta($vendor_id, 'payment_id', true);

		return $payment_id ?: null;
	}


	function init_melli_woocommerce() {

		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}


		class WC_Gateway_Melli extends WC_Payment_Gateway {
			/**
			 * Constructor for the gateway.
			 */
			public string $terminal_id;
			public string $merchant_id;
			public string $terminal_key;
			
			public function __construct() {
				$this->id = 'melli_pay';
				$icon_path = plugin_dir_url(__FILE__) . 'images/logo.webp';
				$this->icon = is_string($icon_path) ? esc_url($icon_path) : '';
				$this->has_fields = false;
				$this->order_button_text =(string) __('پرداخت', 'woocommerce');
				$this->method_title = (string) __('بانک ملی', 'woocommerce');
				$this->title = (string) $this->get_option('title', '');
				$this->description = (string) $this->get_option('description', '');
				$this->method_description = (string) __(
					'درگاه پرداخت بانک ملی! برای استفاده از این درگاه لازم است ماژول curl روی سرور شما فعال باشد.',
					'woocommerce'
				);
				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Define user set variables.
				$this->title = $this->settings['title'];

				$this->terminal_id = $this->settings['terminal_id'];
				$this->merchant_id = $this->settings['merchant_id'];
				$this->terminal_key = $this->settings['terminal_key'];

				if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
					add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				} else {
					add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
				}

				add_action('woocommerce_receipt_' . $this->id, array($this, 'redirect_to_bank'));
				add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'bank_callback'));
			}

			function init_form_fields() {
				$this->form_fields = array(
						'base_confing' => array(
								'title' => __('تنظیمات پایه ای', 'woocommerce'),
								'type' => 'title',
								'description' => '',
						),
						'enabled' => array(
								'title' => __('فعالسازی/غیرفعالسازی', 'woocommerce'),
								'type' => 'checkbox',
								'label' => __('فعالسازی درگاه بانک ملی', 'woocommerce'),
								'description' => __('برای فعالسازی درگاه پرداخت بانک ملی باید چک باکس را تیک بزنید', 'woocommerce'),
								'default' => 'yes',
								'desc_tip' => true,
						),
						'title' => array(
								'title' => __('عنوان درگاه', 'woocommerce'),
								'type' => 'text',
								'description' => __('عنوان درگاه که در طی خرید به مشتری نمایش داده میشود', 'woocommerce'),
								'default' => __('بانک ملی', 'woocommerce'),
								'desc_tip' => true,
						),
						'description' => array(
								'title' => __('توضیحات درگاه', 'woocommerce'),
								'type' => 'text',
								'desc_tip' => true,
								'description' => __('توضیحاتی که در طی عملیات پرداخت برای درگاه نمایش داده خواهد شد', 'woocommerce'),
								'default' => __('پرداخت امن به وسیله کلیه کارت های عضو شتاب از طریق درگاه بانک ملی', 'woocommerce')
						),
						'account_confing' => array(
								'title' => __('تنظیمات حساب بانک ملی', 'woocommerce'),
								'type' => 'title',
								'description' => '',
						),
						'merchant_id' => array(
								'title' => __('شماره پذیرنده', 'woocommerce'),
								'type' => 'text',
								'description' => __('شماره پذیرنده درگاه بانک ملی', 'woocommerce'),
								'default' => '',
								'desc_tip' => true
						),
						'terminal_id' => array(
								'title' => __('شماره ترمینال', 'woocommerce'),
								'type' => 'text',
								'description' => __('شماره ترمینال درگاه بانک ملی', 'woocommerce'),
								'default' => '',
								'desc_tip' => true
						),
						'terminal_key' => array(
								'title' => __('کلید تراکنش', 'woocommerce'),
								'type' => 'text',
								'description' => __('کلید تراکنش درگاه بانک ملی', 'woocommerce'),
								'default' => '',
								'desc_tip' => true
						),
						/*'payment_id' => array(
								'title' => __('شناسه پرداخت', 'woocommerce'),
								'type' => 'text',
								'description' => __('شناسه اختصاصی پرداخت‌های دولتی', 'woocommerce'),
								'default' => '',
								'desc_tip' => true
						),*/
						'payment_confing' => array(
								'title' => __('تنظیمات عملیات پرداخت', 'woocommerce'),
								'type' => 'title',
								'description' => '',
						),
						'success_massage' => array(
								'title' => __('پیام پرداخت موفق', 'woocommerce'),
								'type' => 'textarea',
								'description' => __('متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {transaction_id} برای نمایش کد رهگیری ( کد مرجع تراکنش ) و از شرت کد {SaleOrderId} برای شماره درخواست تراکنش بانک ملی استفاده نمایید .', 'woocommerce'),
								'default' => __('با تشکر از شما، سفارش شما با موفقیت و با کد رهگیری {transaction_id} و شناسه تراکنش  {SaleOrderId} پرداخت شد. ', 'woocommerce'),
						),
						'failed_massage' => array(
								'title' => __('پیام پرداخت ناموفق', 'woocommerce'),
								'type' => 'textarea',
								'description' => __('متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید . این دلیل خطا از سایت بانک ملی ارسال میگردد .', 'woocommerce'),
								'default' => __('پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woocommerce'),
						),
						'cancelled_massage' => array(
								'title' => __('پیام انصراف از پرداخت', 'woocommerce'),
								'type' => 'textarea',
								'description' => __('متن پیامی که میخواهید بعد از انصراف کاربر از پرداخت نمایش دهید را وارد نمایید . این پیام بعد از بازگشت از بانک نمایش داده خواهد شد .', 'woocommerce'),
								'default' => __('پرداخت به دلیل انصراف شما ناتمام باقی ماند .', 'woocommerce'),
						),
				);
			}

			/**
			 * admin_options
			 *
			 * Renders configuration form for the gateway
			 */
			public function admin_options() {
				echo '<h3>' . __('درگاه پرداخت بانک ملی', 'wpmaster') . '</h3>';
				echo '<p>' . __('درگاه پرداخت بانک ملی') . '</p>';
				$this->generate_settings_html();
			}


			/**
			 * Receipt Page
			 **/
			function redirect_to_bank($order_id) {
				WC()->session->set('order_id_sadadpsp', $order_id);
				$order = wc_get_order($order_id);
				$items = $order->get_items();
				foreach ( $items as $item ) {
					$product_id = $item->get_product_id();
					$product_ids = [];
					$product_ids[] = $product_id;
				}
				$payment_id = get_product_vendor_payment_id($product_id);
				// echo $payment_id;
				$currency = $order->get_currency();

				$form = '<form action="" method="POST" id="mw-checkout-form">
						<input type="submit" name="mw_submit" class="button alt" value="' . __('پرداخت', 'woocommerce') . '"/>
						<a class="button cancel" href="' . wc_get_checkout_url() . '">' . __('بازگشت', 'woocommerce') . '</a>
					 </form><br/>';
				echo $form;

				if (isset($_POST["mw_submit"])) {
					$Amount = $this->get_price(intval($order->get_total()), $currency);

					$terminal_id = $this->terminal_id;
					$merchant_id = $this->merchant_id;
					$terminal_key = $this->terminal_key;
					$payment_id = $payment_id;

					$orderId = date('ymdHis');
					$callBackUrl = add_query_arg('wc_order', $order_id, WC()->api_request_url('WC_Gateway_Melli'));

					$sign_data = $this->sadad_encrypt($terminal_id . ';' . $orderId . ';' . $Amount, $terminal_key);
					$parameters = array(
							'MerchantID' => $merchant_id,
							'TerminalId' => $terminal_id,
							'Amount' => $Amount,
							'OrderId' => $orderId,
							'LocalDateTime' => date('Ymdhis'),
							'ReturnUrl' => $callBackUrl,
							'SignData' => $sign_data,
							'PaymentIdentity' => $payment_id,
					);

					$error_flag = false;
					$error_msg = '';
					$result = $this->sadad_call_api('https://sadad.shaparak.ir/api/v0/PaymentByIdentity/PaymentRequest', $parameters);

					if ($result != false) {
						if ($result->ResCode == 0) {
							//header('Location: https://sadad.shaparak.ir/VPG/Purchase?Token=' . $res->Token);
							echo '<form id="redirect_to_melli" method="get" action="https://sadad.shaparak.ir/VPG/Purchase" style="display:none !important;"  >
										<input type="hidden"  name="Token" value="' . $result->Token . '" />
										<input type="submit" value="Pay"/>
									</form>
									<script language="JavaScript" type="text/javascript">
										document.getElementById("redirect_to_melli").submit();
									</script>';

						} else {
							//bank returned an error
							$error_flag = true;
							$error_msg = 'خطا در برقراری ارتباط با بانک! ' . $this->sadad_request_err_msg($result->ResCode);
						}
					} else {
						// couldn't connect to bank
						$error_flag = true;
						$error_msg = 'خطا! برقراری ارتباط با بانک امکان پذیر نیست.';
					}
					if ($error_flag) {
						$order->add_order_note($error_msg);
						wc_add_notice($error_msg, 'error');
					}
				}

			}

			/**
			 * Process the payment and return the result
			 **/
			function process_payment($order_id) {
				$order = wc_get_order($order_id);
				return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
			}

			/**
			 * Check for valid melli server callback
			 **/
			function bank_callback() {

				if (isset($_GET['wc_order'])) {
					$order_id = absint($_GET['wc_order'] ?? 0);
				} else {
					$order_id = WC()->session->get('order_id_sadadpsp');
				}

				if ($order_id) {

					$order = wc_get_order($order_id);

					if ($order->get_status() !== 'completed') {

						$terminal_key = $this->terminal_key;

						if (isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {
							$token = sanitize_text_field($_POST['token'] ?? '');

							//verify payment
							$parameters = array(
									'Token' => $token,
									'SignData' => $this->sadad_encrypt($token, $terminal_key),
							);

							$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);


							if ($result != false) {
								if ($result->ResCode == 0) {
									$RetrivalRefNo = $result->RetrivalRefNo;
									$TraceNo = $result->SystemTraceNo;
									$OrderId = $result->OrderId;

									update_post_meta($order_id, 'WC_Gateway_Melli_OrderId', $OrderId);
									update_post_meta($order_id, 'WC_Gateway_Melli_RetrivalRefNo', $RetrivalRefNo);
									update_post_meta($order_id, 'WC_Gateway_Melli_TraceNo', $TraceNo);

									$order->payment_complete($TraceNo);
									WC()->cart->empty_cart();

									$Note = __('پرداخت موفقیت آمیز بود.', 'woocommerce') . '<br>';
									$Note .= __("کد رهگیری (کد مرجع تراکنش): {$RetrivalRefNo}", 'woocommerce') . '<br>';
									$Note .= __("شماره درخواست تراکنش: {$TraceNo}", 'woocommerce') . '<br>';

									$order->add_order_note($Note);

									$Notice = wpautop(wptexturize($this->settings['success_massage']));
									$Notice = str_replace("{transaction_id}", $RetrivalRefNo, $Notice);
									$Notice = str_replace("{SaleOrderId}", $TraceNo, $Notice);

									wc_add_notice($Notice, 'success');

									wp_safe_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
									exit;

								} else {
									//couldn't verify the payment due to a back error
									$error_flag = true;
									$error_msg = 'خطا هنگام پرداخت! ' . $this->sadad_verify_err_msg($result->ResCode);
								}
							} else {
								//couldn't verify the payment due to a connection failure to bank
								$error_flag = true;
								$error_msg = 'خطا! عدم امکان دریافت تاییدیه پرداخت از بانک';
							}
							if ($error_flag) {
								wc_add_notice($error_msg, 'error');
								wp_safe_redirect(wc_get_checkout_url());
								exit;
							}


						}else{

						}

					} else {
						$Notice = wpautop(wptexturize($this->settings['success_massage']));
						wc_add_notice($Notice, 'success');
						wp_safe_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
						exit;
					}
				} else {
					$error_msg = __('شماره سفارش وجود ندارد .', 'woocommerce');
					wc_add_notice($error_msg, 'error');
					wp_safe_redirect(wc_get_checkout_url());
					exit;
				}
			}

			private function get_price($amount, $currency) {
				$currencies = array('IRT', 'TOMAN', 'Iran TOMAN', 'Iranian TOMAN', 'Iran-TOMAN', 'Iranian-TOMAN', 'Iran_TOMAN', 'تومان ایران', 'تومان', 'Iranian_TOMAN');
				if (in_array($currency, $currencies)) {
					return $amount * 10;
				}
				return $amount;
			}

			//Create sign data(Tripledes(ECB,PKCS7)
			private function encrypt_pkcs7($data, $key)
			{
				// کلید باید 24 بایت باشه (3DES)
				$key = substr($key, 0, 24);
				// PKCS7 padding
				$blockSize = 8;
				$pad = $blockSize - (strlen($data) % $blockSize);
				$data .= str_repeat(chr($pad), $pad);
				$encrypted = openssl_encrypt(
					$data,
					'DES-EDE3',
					$key,
					OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
				);
				return base64_encode($encrypted);
			}

			//Create sign data(Tripledes(ECB,PKCS7)) using openssl
			private function openssl_encrypt_pkcs7($key, $data)
			{
				// کلید باید 24 بایت باشد
				$key = substr($key, 0, 24);
				// PKCS7 Padding
				$blockSize = 8;
				$pad = $blockSize - (strlen($data) % $blockSize);
				$data .= str_repeat(chr($pad), $pad);
				$encrypted = openssl_encrypt(
					$data,
					'DES-EDE3',
					$key,
					OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
				);
				return base64_encode($encrypted);
			}


			private function sadad_encrypt($data, $key) {
				$key = base64_decode($key);
				if( function_exists('openssl_encrypt') ) {
					return $this->openssl_encrypt_pkcs7($key, $data);
//				} elseif( function_exists('mcrypt_encrypt') ) {
//					return $this->encrypt_pkcs7($data, $key);
				} else {
					require_once 'TripleDES.php';
					$cipher = new Crypt_TripleDES();
					return $cipher->letsEncrypt($key, $data);
				}

			}

			private function sadad_call_api($url, $data = false)
			{
				$ch = curl_init();

				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_HTTPHEADER, [
					'Content-Type: application/json; charset=utf-8'
				]);
				curl_setopt($ch, CURLOPT_POST, true);

				if ($data !== false) {
					curl_setopt(
						$ch,
						CURLOPT_POSTFIELDS,
						json_encode(
							$data,
							JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						)
					);
				}

				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);

				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

				$result = curl_exec($ch);

				// if ($result === false) {
				// 	error_log('===== SADAD CURL ERROR =====');
				// 	error_log('URL: ' . $url);
				// 	error_log('cURL errno: ' . curl_errno($ch));
				// 	error_log('cURL error: ' . curl_error($ch));
				// } else {
				// 	error_log('===== SADAD HTTP =====');
				// 	error_log('HTTP Code: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
				// 	error_log('Response: ' . $result);
				// }

				curl_close($ch);

				$response = json_decode($result);

				return json_last_error() === JSON_ERROR_NONE
					? $response
					: false;
			}

			private function sadad_request_err_msg($err_code) {

				$message = 'در حین پرداخت خطای سیستمی رخ داده است .';
				switch ($err_code) {
					case 3:
						$message = 'پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.';
						break;
					case 23:
						$message = 'پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.';
						break;
					case 58:
						$message = 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.';
						break;
					case 61:
						$message = 'مبلغ تراکنش از حد مجاز بالاتر است.';
						break;
					case 1000:
						$message = 'ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.';
						break;
					case 1001:
						$message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.';
						break;
					case 1002:
						$message = 'خطا در سیستم- تراکنش ناموفق';
						break;
					case 1003:
						$message = 'آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.';
						break;
					case 1004:
						$message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.';
						break;
					case 1005:
						$message = 'خطای دسترسی:لطفا بعدا تلاش فرمايید.';
						break;
					case 1006:
						$message = 'خطا در سیستم';
						break;
					case 1011:
						$message = 'درخواست تکراری- شماره سفارش تکراری می باشد.';
						break;
					case 1012:
						$message = 'اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
						اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.';
						break;
					case 1015:
						$message = 'پاسخ خطای نامشخص از سمت مرکز';
						break;
					case 1017:
						$message = 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است';
						break;
					case 1018:
						$message = 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید';
						break;
					case 1019:
						$message = 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست';
						break;
					case 1020:
						$message = 'پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد';
						break;
					case 1023:
						$message = 'آدرس بازگشت پذيرنده نامعتبر است';
						break;
					case 1024:
						$message = 'مهر زمانی پذيرنده نامعتبر است';
						break;
					case 1025:
						$message = 'امضا تراکنش نامعتبر است';
						break;
					case 1026:
						$message = 'شماره سفارش تراکنش نامعتبر است';
						break;
					case 1027:
						$message = 'شماره پذيرنده نامعتبر است';
						break;
					case 1028:
						$message = 'شماره ترمینال پذيرنده نامعتبر است';
						break;
					case 1029:
						$message = 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
						break;
					case 1030:
						$message = 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
						break;
					case 1031:
						$message = 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .';
						break;
					case 1032:
						$message = 'پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.';
						break;
					case 1033:
						$message = 'به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
						است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.';
						break;
					case 1036:
						$message = 'اطلاعات اضافی ارسال نشده يا دارای اشکال است';
						break;
					case 1037:
						$message = 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد';
						break;
					case 1053:
						$message = 'خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.';
						break;
					case 1055:
						$message = 'مقدار غیرمجاز در ورود اطلاعات';
						break;
					case 1056:
						$message = 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.';
						break;
					case 1058:
						$message = 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.';
						break;
					case 1061:
						$message = 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(';
						break;
					case 1064:
						$message = 'لطفا مجددا سعی بفرمايید';
						break;
					case 1065:
						$message = 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید';
						break;
					case 1066:
						$message = 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است';
						break;
					case 1068:
						$message = 'با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.';
						break;
					case 1072:
						$message = 'خطا در پردازش پارامترهای اختیاری پذيرنده';
						break;
					case 1101:
						$message = 'مبلغ تراکنش نامعتبر است';
						break;
					case 1103:
						$message = 'توکن ارسالی نامعتبر است';
						break;
					case 1104:
						$message = 'اطلاعات تسهیم صحیح نیست';
						break;
					default:
						$message = 'خطای نامشخص';
				}
				return __($message, 'woocommerce');
			}

			private function sadad_verify_err_msg($res_code) {
				$error_text = '';
				switch ($res_code) {
					case -1:
					case '-1':
						$error_text = 'پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.';
						break;
					case 101:
					case '101':
						$error_text = 'مهلت ارسال تراکنش به پايان رسیده است.';
						break;
				}
				return __($error_text, 'woocommerce');
			}


		}

		/**
		 * Add the Gateway to WooCommerce
		 **/
		function add_woocommerce_melli_gateway($methods) {
			$methods[] = 'WC_Gateway_Melli';
			return $methods;
		}

		add_filter('woocommerce_payment_gateways', 'add_woocommerce_melli_gateway');
	}


	add_filter('woocommerce_add_to_cart_validation', 'restrict_cart_to_single_vendor', 10, 3);

	function restrict_cart_to_single_vendor($passed, $product_id, $quantity) {

		if (WC()->cart->is_empty()) {
			return true;
		}

		$new_vendor = (int) get_post_field('post_author', $product_id);

		foreach (WC()->cart->get_cart() as $cart_item) {

			$existing_vendor = (int) get_post_field(
				'post_author',
				$cart_item['product_id']
			);

			if ($existing_vendor !== $new_vendor) {

				wc_add_notice(
					'شما فقط می‌توانید محصولات یک وندور را همزمان به سبد خرید اضافه کنید.',
					'error'
				);

				// فلگ برای AJAX
				add_filter('woocommerce_cart_redirect_after_error', '__return_false');

				return false;
			}
		}

		return true;
	}

	add_action('wp_enqueue_scripts', 'enqueue_vendor_cart_notice_script', 20);

	function enqueue_vendor_cart_notice_script() {

		if (is_admin()) {
			return;
		}

		// فقط وقتی ووکامرس فعال است
		if (!class_exists('WooCommerce')) {
			return;
		}

		// فقط صفحات فروشگاهی
		if (!is_product() && !is_shop() && !is_product_category() && !is_cart()) {
			return;
		}

		wp_enqueue_script(
			'vendor-cart-notice',
			plugin_dir_url(__FILE__) . 'main.js',
			['jquery', 'wc-add-to-cart'],
			'1.0.0',
			true
		);
	}

?>
