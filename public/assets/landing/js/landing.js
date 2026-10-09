  /* =========================
       PLATFORM RATES
    ========================= */

    let USDT_RATE = 110;
    let USD_RATE = 110;


    /* =========================
       USDT CALCULATOR
    ========================= */

    function convertUSDT() {

        let amount =
            parseFloat(
                document.getElementById("usdtInput").value
            ) || 0;

        let result =
            amount * USDT_RATE;

        document.getElementById("usdtResult")
            .innerText =
            result.toLocaleString(
                "en-IN",
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );
    }


    /* =========================
       USD / USDT CALCULATOR
    ========================= */

    function calculateCurrency() {

        let type =
            document.getElementById("currencyType").value;

        let amount =
            parseFloat(
                document.getElementById("currencyAmount").value
            ) || 0;

        let rate =
            type === "USDT"
                ? USDT_RATE
                : USD_RATE;

        let result = amount * rate;

        document.getElementById("currencyResult")
            .innerText =
            result.toLocaleString(
                "en-IN",
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );
    }


    /* =========================
       EXCHANGE BUTTON
    ========================= */

    function startExchange() {
        window.location.assign(document.body.dataset.loginUrl);
    }


    /* =========================
       INITIAL LOAD
    ========================= */

    document.addEventListener(
        "DOMContentLoaded",
        function () {

            convertUSDT();
            calculateCurrency();

        }
    );