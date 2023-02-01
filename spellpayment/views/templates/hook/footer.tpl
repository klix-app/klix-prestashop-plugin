{if $page.page_name == "checkout" || $page.page_name == "product" || $page.page_name == "cart" }
    <script type="text/javascript">
        document.getElementById("spellpayment").addEventListener("click", function() {
            let url = document.getElementById("spellpayment").getAttribute("data-url");
            {if $page.page_name == "product"}
                let qty = document.querySelector('[name=qty]');
                if (qty) {
                    let id_product_attribute = document.getElementById("spellpayment").getAttribute("data-ipa");
                    qty = qty.value;
                    url = url + "&id_product_attribute=" + id_product_attribute + "&qty=" + qty;
                }
            {/if}

            window.location.href = url;
        });
    </script>
{/if}