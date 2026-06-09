 (() => {
    let settings = {};

    function monimeGatewayFields() {
      const message = settings.message || {};

      return window.wp.element.createElement(
        "div",
        { className: "monime-gateway-fields" },

        window.wp.element.createElement(
          "strong",
          { style: { display: "block", marginBottom: "8px" } },
          message.name || "Accepted Payment Providers"
        ),

        window.wp.element.createElement(
          "p",
          { style: { marginTop: 0, marginBottom: "12px" } },
          message.desc || ""
        ),

        window.wp.element.createElement("div", {
          className: "monime-provider-icons",
          dangerouslySetInnerHTML: {
            __html: message.html || ""
          }
        })
      );
    }

    const monimeGateway = {
      id: "monime",

      initialize() {
        settings = this.settings || {};
      },

      Fields() {
        return window.wp.element.createElement(monimeGatewayFields);
      },
    };

    window.givewp.gateways.register(monimeGateway);
  })();
