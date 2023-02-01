{if $page.page_name == "product"}
	<klix-pay-later amount="{$product_price}" brand_id="{$brand_id}" 
      language="{$language}" theme="light" view="product"></klix-pay-later>
	{if $one_click_button_enabled }
		<a data-url={$url} data-ipa={$product.id_product_attribute}  id='spellpayment' class='btn btn-primary' style='display:flex;justify-content:center;margin:10px;' id='spellpayment' class='btn btn-primary'>
			<i class='material-icons shopping-cart'></i>
			{l s='Pay now' d='Modules.Spellpayment.Paynow'}
		</a>
	{/if}
{elseif $page.page_name == "cart" && !empty($cart.products) }
{if $one_click_button_enabled }
	<a data-url={$url} style='display:inline-flex;justify-content:center;float:right;' id='spellpayment' class='btn btn-primary'>
		<i class='material-icons shopping-cart'></i>
		{l s='Pay now' d='Modules.Spellpayment.Paynow'}
	</a>
	{/if}
{elseif $page.page_name == "checkout" && !empty($cart.products) }
	{if $one_click_button_enabled}
		<a data-url={$url} style='display:flex;justify-content:center;margin:10px;' id='spellpayment' class='btn btn-primary'>
			<i class='material-icons shopping-cart'></i>
			{l s='Pay now' d='Modules.Spellpayment.Paynow'}
		</a>
	{/if}
{/if}