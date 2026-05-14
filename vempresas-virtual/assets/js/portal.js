(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('.vev-portal');
    if (!root) return;

    var tabs = root.querySelectorAll('[data-vev-tab]');
    var panels = root.querySelectorAll('[data-vev-panel]');
    var menu = root.querySelector('.vev-menu');
    var welcome = root.querySelector('.vev-welcome');

    function showMenu() {
      if (menu) menu.hidden = false;
      if (welcome) welcome.hidden = false;
      tabs.forEach(function (item) {
        item.classList.remove('is-active');
      });
      panels.forEach(function (panel) {
        panel.classList.remove('is-active');
      });
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var key = tab.getAttribute('data-vev-tab');
        tabs.forEach(function (item) {
          item.classList.toggle('is-active', item === tab);
        });
        if (menu) menu.hidden = true;
        if (welcome) welcome.hidden = true;
        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-vev-panel') === key);
        });
      });
    });

    root.querySelectorAll('[data-vev-back-menu]').forEach(function (button) {
      button.addEventListener('click', showMenu);
    });

    showMenu();
    try {
      var initialPanel = new URLSearchParams(window.location.search).get('vev_panel');
      if (initialPanel) {
        var initialTab = root.querySelector('[data-vev-tab="' + initialPanel.replace(/[^a-z0-9_-]/gi, '') + '"]');
        if (initialTab) initialTab.click();
      }
    } catch (err) {}

    function postJson(action, payload) {
      return fetch(VEV_PORTAL.ajaxUrl + '?action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(VEV_PORTAL.nonce), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      }).then(function (res) {
        return res.json();
      });
    }

    var cart = [];
    var cartBox = document.getElementById('vevCart');
    var cartItems = document.getElementById('vevCartItems');
    var cartEmpty = document.getElementById('vevCartEmpty');
    var cartTotal = document.getElementById('vevCartTotal');
    var cartCheckout = document.getElementById('vevCartCheckout');
    var cartClear = document.getElementById('vevCartClear');
    var paymentModal = document.getElementById('vevPaymentModal');
    var modalSummary = document.getElementById('vevModalSummary');
    var paymentBox = document.getElementById('vevPaymentBox');

    function escapeHtml(value) {
      return String(value || '').replace(/[&<>"']/g, function (char) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[char];
      });
    }

    function formatMoney(amount) {
      try {
        return new Intl.NumberFormat('es-MX', {
          style: 'currency',
          currency: 'MXN'
        }).format(Number(amount) || 0);
      } catch (err) {
        return 'MX$' + (Number(amount) || 0).toFixed(2);
      }
    }

    function priceFor(product, quantity) {
      var unit = Number(product.unitPrice) || 0;
      var discount = 0;

      if (product.type !== 'package' && Array.isArray(product.rules)) {
        product.rules.forEach(function (rule) {
          if (quantity >= Number(rule.minQuantity || 0)) {
            discount = Number(rule.discountPercent) || 0;
          }
        });
      }

      return {
        discount: discount,
        total: unit * quantity * (1 - discount / 100)
      };
    }

    function cartSubtotal() {
      return cart.reduce(function (sum, item) {
        return sum + priceFor(item.product, item.quantity).total;
      }, 0);
    }

    function paymentPayload() {
      return {
        items: cart.map(function (item) {
          return {
            product_id: item.product.id,
            quantity: item.quantity
          };
        }),
        portalUrl: cartBox ? cartBox.getAttribute('data-portal-url') : window.location.href
      };
    }

    function setPaymentStatus(message, type, channel) {
      if (!paymentBox) return;
      var status = channel
        ? paymentBox.querySelector('.vev-payment-status--' + channel)
        : paymentBox.querySelector('.vev-payment-status');
      if (!status) return;
      status.textContent = message || '';
      status.className = 'vev-payment-status' + (channel ? ' vev-payment-status--' + channel : '');
      if (type) status.classList.add('is-' + type);
    }

    function showPaymentPanel(active) {
      if (!paymentBox) return;
      var stripePanel = paymentBox.querySelector('.vev-stripe-panel');
      var paypalPanel = paymentBox.querySelector('.vev-paypal-panel');

      if (stripePanel) stripePanel.hidden = active !== 'stripe';
      if (paypalPanel) paypalPanel.hidden = active !== 'paypal';
    }

    function resetPayment() {
      if (!paymentBox) return;
      var stripeCheckout = paymentBox.querySelector('.vev-stripe-checkout');
      var paypalButtons = paymentBox.querySelector('.vev-paypal-buttons');

      if (paymentBox._vevStripeCheckout && paymentBox._vevStripeCheckout.destroy) {
        paymentBox._vevStripeCheckout.destroy();
      }

      paymentBox._vevStripeCheckout = null;
      paymentBox._vevStripeLoading = false;
      paymentBox._vevPaypalRendered = false;
      if (stripeCheckout) stripeCheckout.innerHTML = '';
      if (paypalButtons) paypalButtons.innerHTML = '';
      setPaymentStatus('', '', 'stripe');
      setPaymentStatus('', '', 'paypal');
      showPaymentPanel('');
    }

    function renderCart() {
      if (!cartItems || !cartEmpty || !cartTotal || !cartCheckout) return;

      cartEmpty.hidden = cart.length > 0;
      cartCheckout.disabled = cart.length === 0;
      cartTotal.textContent = formatMoney(cartSubtotal());

      cartItems.innerHTML = cart.map(function (item, index) {
        var line = priceFor(item.product, item.quantity);
        var quantityLabel = item.product.type === 'package' ? 'paquetes' : 'consultas';
        var discount = line.discount ? '<span class="vev-cart-line__discount">' + line.discount + '% desc.</span>' : '';

        return '<div class="vev-cart-line" data-index="' + index + '">'
          + '<div>'
          + '<span class="vev-cart-line__type">' + escapeHtml(item.product.typeLabel) + '</span>'
          + '<strong>' + escapeHtml(item.product.name) + '</strong>'
          + '<small>' + item.quantity + ' ' + quantityLabel + ' ' + discount + '</small>'
          + '</div>'
          + '<div class="vev-cart-line__controls">'
          + '<input type="number" min="1" value="' + item.quantity + '" aria-label="Cantidad">'
          + '<button type="button" data-remove-cart>Quitar</button>'
          + '</div>'
          + '<span class="vev-cart-line__total">' + formatMoney(line.total) + '</span>'
          + '</div>';
      }).join('');
    }

    function addToCart(product, quantity) {
      var existing = cart.find(function (item) {
        return Number(item.product.id) === Number(product.id);
      });

      if (existing) {
        existing.quantity += quantity;
      } else {
        cart.push({
          product: product,
          quantity: quantity
        });
      }

      renderCart();
    }

    root.querySelectorAll('.vev-add-cart').forEach(function (button) {
      button.addEventListener('click', function () {
        var card = button.closest('.vev-product');
        var qtyInput = card ? card.querySelector('.vev-card-qty') : null;
        var quantity = Math.max(1, parseInt(qtyInput && qtyInput.value, 10) || 1);
        var product = JSON.parse(button.getAttribute('data-product') || '{}');

        if (!product.id) return;
        addToCart(product, quantity);
        button.textContent = 'Añadido';
        window.setTimeout(function () {
          button.textContent = 'Añadir a carrito';
        }, 900);
      });
    });

    if (cartItems) {
      cartItems.addEventListener('input', function (event) {
        var input = event.target.closest('.vev-cart-line__controls input');
        if (!input) return;
        var line = input.closest('.vev-cart-line');
        var index = parseInt(line.getAttribute('data-index'), 10);
        if (!cart[index]) return;
        cart[index].quantity = Math.max(1, parseInt(input.value, 10) || 1);
        renderCart();
      });

      cartItems.addEventListener('click', function (event) {
        if (!event.target.closest('[data-remove-cart]')) return;
        var line = event.target.closest('.vev-cart-line');
        var index = parseInt(line.getAttribute('data-index'), 10);
        cart.splice(index, 1);
        renderCart();
      });
    }

    if (cartClear) {
      cartClear.addEventListener('click', function () {
        cart = [];
        renderCart();
      });
    }

    function renderModalSummary() {
      if (!modalSummary) return;

      modalSummary.innerHTML = cart.map(function (item) {
        var line = priceFor(item.product, item.quantity);
        return '<div class="vev-modal-summary__row">'
          + '<span>' + escapeHtml(item.product.name) + '</span>'
          + '<small>' + item.quantity + ' x ' + escapeHtml(item.product.type === 'package' ? 'paquete' : 'consulta') + '</small>'
          + '<strong>' + formatMoney(line.total) + '</strong>'
          + '</div>';
      }).join('') + '<div class="vev-modal-summary__total"><span>Total</span><strong>' + formatMoney(cartSubtotal()) + '</strong></div>';
    }

    function openPaymentModal() {
      if (!paymentModal || cart.length === 0) return;
      resetPayment();
      renderModalSummary();
      paymentModal.hidden = false;
      document.documentElement.classList.add('vev-modal-open');
    }

    function closePaymentModal() {
      if (!paymentModal) return;
      paymentModal.hidden = true;
      resetPayment();
      document.documentElement.classList.remove('vev-modal-open');
    }

    if (cartCheckout) {
      cartCheckout.addEventListener('click', openPaymentModal);
    }

    if (paymentModal) {
      paymentModal.querySelectorAll('[data-vev-close-payment]').forEach(function (button) {
        button.addEventListener('click', closePaymentModal);
      });
    }

    if (paymentBox) {
      var stripeTrigger = paymentBox.querySelector('.vev-pay-option--stripe');
      var stripeCheckout = paymentBox.querySelector('.vev-stripe-checkout');
      var paypalTrigger = paymentBox.querySelector('.vev-pay-option--paypal');
      var paypalButtons = paymentBox.querySelector('.vev-paypal-buttons');

      if (stripeTrigger && stripeCheckout) {
        stripeTrigger.addEventListener('click', function () {
          showPaymentPanel('stripe');

          if (!VEV_PORTAL.stripePublicKey || !window.Stripe) {
            setPaymentStatus('Stripe no está configurado todavía. Revisa STRIPE_PUBLIC_KEY y STRIPE_SECRET_KEY.', 'error', 'stripe');
            return;
          }

          if (paymentBox._vevStripeCheckout || paymentBox._vevStripeLoading) {
            setPaymentStatus('El formulario de tarjeta ya está listo.', 'info', 'stripe');
            return;
          }

          paymentBox._vevStripeLoading = true;
          stripeCheckout.innerHTML = '';
          setPaymentStatus('Cargando formulario de tarjeta...', 'info', 'stripe');

          postJson('vev_stripe_create_session', paymentPayload())
            .then(function (json) {
              if (!json.success) {
                throw new Error((json.data && json.data.message) || 'No se pudo iniciar Stripe.');
              }

              var stripe = window.Stripe(VEV_PORTAL.stripePublicKey);
              return stripe.initEmbeddedCheckout({
                clientSecret: json.data.clientSecret
              });
            })
            .then(function (checkout) {
              paymentBox._vevStripeCheckout = checkout;
              checkout.mount(stripeCheckout);
              setPaymentStatus('Completa el pago con tarjeta aquí.', 'info', 'stripe');
            })
            .catch(function (err) {
              paymentBox._vevStripeCheckout = null;
              setPaymentStatus((err && err.message) || 'No se pudo cargar Stripe.', 'error', 'stripe');
            })
            .finally(function () {
              paymentBox._vevStripeLoading = false;
            });
        });
      }

      if (paypalTrigger && paypalButtons) {
        paypalTrigger.addEventListener('click', function () {
          showPaymentPanel('paypal');

          if (!VEV_PORTAL.hasPayPal || !window.paypal || !window.paypal.Buttons) {
            setPaymentStatus('PayPal no está configurado todavía. Revisa PAYPAL_CLIENT_ID y PAYPAL_CLIENT_SECRET.', 'error', 'paypal');
            return;
          }

          setPaymentStatus('Selecciona PayPal para continuar.', 'info', 'paypal');

          if (paymentBox._vevPaypalRendered) return;
          paymentBox._vevPaypalRendered = true;

          window.paypal.Buttons({
            style: {
              layout: 'vertical',
              shape: 'rect',
              label: 'paypal'
            },
            createOrder: function () {
              setPaymentStatus('Creando orden de PayPal...', 'info', 'paypal');
              return postJson('vev_paypal_create_order', paymentPayload()).then(function (json) {
                if (!json.success) {
                  throw new Error((json.data && json.data.message) || 'No se pudo crear la orden de PayPal.');
                }
                return json.data.orderID;
              });
            },
            onApprove: function (data) {
              setPaymentStatus('Confirmando pago...', 'info', 'paypal');
              return postJson('vev_paypal_capture_order', { orderID: data.orderID }).then(function (json) {
                if (!json.success) {
                  throw new Error((json.data && json.data.message) || 'PayPal no confirmó el pago.');
                }
                setPaymentStatus(json.data.message || 'Pago confirmado.', 'success', 'paypal');
                window.setTimeout(function () {
                  window.location.reload();
                }, 1200);
              });
            },
            onCancel: function () {
              setPaymentStatus('Pago cancelado.', 'error', 'paypal');
            },
            onError: function (err) {
              setPaymentStatus((err && err.message) || 'PayPal no pudo completar el pago.', 'error', 'paypal');
            }
          }).render(paypalButtons).catch(function (err) {
            paymentBox._vevPaypalRendered = false;
            setPaymentStatus((err && err.message) || 'No se pudo cargar PayPal.', 'error', 'paypal');
          });
        });
      }
    }

    renderCart();

    var rows = document.getElementById('vevRows');
    var template = document.getElementById('vevRowTemplate');
    var addRowBtn = document.getElementById('vevAddRow');
    var scheduler = document.getElementById('vevScheduler');
    var results = document.getElementById('vevScheduleResults');

    function fillSelect(select, options, placeholder) {
      select.innerHTML = '';
      var empty = document.createElement('option');
      empty.value = '';
      empty.textContent = placeholder;
      select.appendChild(empty);
      options.forEach(function (option) {
        var item = document.createElement('option');
        item.value = option.value;
        item.textContent = option.label;
        if (option.data) {
          Object.keys(option.data).forEach(function (key) {
            item.dataset[key] = option.data[key];
          });
        }
        select.appendChild(item);
      });
    }

    function rowDoctorMode(row) {
      return row.dataset.doctorMode === 'manual' ? 'manual' : 'auto';
    }

    function setRowDoctorMode(row, mode) {
      row.dataset.doctorMode = mode === 'manual' ? 'manual' : 'auto';
      row.querySelectorAll('[data-vev-row-doctor-mode]').forEach(function (button) {
        var active = button.getAttribute('data-vev-row-doctor-mode') === row.dataset.doctorMode;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });

      var provider = row.querySelector('.vev-provider');
      var providerLabel = row.querySelector('.vev-provider-wrap span');
      if (providerLabel) {
        providerLabel.textContent = row.dataset.doctorMode === 'manual' ? 'Especialista' : 'Especialista disponible';
      }

      fillSelect(provider, [], row.dataset.doctorMode === 'manual' ? 'Primero elige servicio' : 'Primero elige horario');
      row._vevProviderMap = {};
      row._vevProviders = {};

      if (row.dataset.doctorMode === 'manual') {
        loadServiceProviders(row);
      } else {
        loadSlots(row);
      }
    }

    function loadServiceProviders(row) {
      var credit = row.querySelector('.vev-credit');
      var provider = row.querySelector('.vev-provider');

      fillSelect(provider, [], 'Cargando especialistas...');

      if (!credit.value) {
        fillSelect(provider, [], 'Primero elige servicio');
        return;
      }

      var url = VEV_PORTAL.ajaxUrl + '?action=vev_get_providers&nonce=' + encodeURIComponent(VEV_PORTAL.nonce)
        + '&creditId=' + encodeURIComponent(credit.value)
        + '&_=' + Date.now();

      fetch(url, { cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (!json.success) {
            fillSelect(provider, [], (json.data && json.data.message) || 'Sin especialistas');
            return;
          }

          var providers = json.data.providers || [];
          fillSelect(provider, providers.map(function (item) {
            return { value: item.id, label: item.name || ('Doctor #' + item.id) };
          }), providers.length ? 'Selecciona especialista' : 'Sin especialistas');
        })
        .catch(function () {
          fillSelect(provider, [], 'Error al cargar especialistas');
        });
    }

    function loadSlots(row) {
      var mode = rowDoctorMode(row);
      var credit = row.querySelector('.vev-credit');
      var date = row.querySelector('.vev-date');
      var time = row.querySelector('.vev-time');
      var provider = row.querySelector('.vev-provider');
      var providerId = mode === 'manual' ? provider.value : '';

      fillSelect(time, [], 'Cargando...');
      if (mode === 'auto') {
        fillSelect(provider, [], 'Primero elige horario');
      }

      if (!credit.value || !date.value) {
        fillSelect(time, [], 'Primero elige servicio y fecha');
        return;
      }

      if (mode === 'manual' && !providerId) {
        fillSelect(time, [], 'Primero elige especialista');
        return;
      }

      var url = VEV_PORTAL.ajaxUrl + '?action=vev_get_slots&nonce=' + encodeURIComponent(VEV_PORTAL.nonce)
        + '&creditId=' + encodeURIComponent(credit.value)
        + '&date=' + encodeURIComponent(date.value)
        + '&providerId=' + encodeURIComponent(providerId)
        + '&_=' + Date.now();

      fetch(url, { cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (!json.success) {
            fillSelect(time, [], (json.data && json.data.message) || 'Sin horarios');
            return;
          }

          row._vevProviderMap = json.data.availability.providerMap || {};
          row._vevProviders = json.data.providers || {};

          var slots = json.data.availability.slots && json.data.availability.slots[date.value]
            ? json.data.availability.slots[date.value]
            : [];

          fillSelect(time, slots.map(function (slot) {
            return { value: slot, label: slot };
          }), slots.length ? 'Selecciona horario' : 'Sin horarios disponibles');
        })
        .catch(function () {
          fillSelect(time, [], 'Error al cargar horarios');
        });
    }

    function loadProvidersForTime(row) {
      if (rowDoctorMode(row) === 'manual') return;

      var date = row.querySelector('.vev-date').value;
      var time = row.querySelector('.vev-time').value;
      var provider = row.querySelector('.vev-provider');
      var map = row._vevProviderMap || {};
      var names = row._vevProviders || {};
      var ids = map[date] && map[date][time] ? map[date][time] : [];

      fillSelect(provider, ids.map(function (id) {
        return { value: id, label: names[id] || ('Doctor #' + id) };
      }), ids.length > 1 ? 'Elige especialista' : (ids.length ? 'Especialista asignado' : 'Sin especialistas'));

      if (ids.length === 1) {
        provider.value = String(ids[0]);
      }
    }

    function bindRow(row) {
      var credit = row.querySelector('.vev-credit');
      var date = row.querySelector('.vev-date');
      var time = row.querySelector('.vev-time');
      var provider = row.querySelector('.vev-provider');
      var remove = row.querySelector('.vev-remove-row');

      setRowDoctorMode(row, 'auto');

      row.querySelectorAll('[data-vev-row-doctor-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
          setRowDoctorMode(row, button.getAttribute('data-vev-row-doctor-mode'));
        });
      });

      credit.addEventListener('change', function () {
        if (rowDoctorMode(row) === 'manual') {
          loadServiceProviders(row);
        }
        loadSlots(row);
      });
      date.addEventListener('change', function () { loadSlots(row); });
      time.addEventListener('change', function () { loadProvidersForTime(row); });
      provider.addEventListener('change', function () {
        if (rowDoctorMode(row) === 'manual') {
          loadSlots(row);
        }
      });
      remove.addEventListener('click', function () {
        if (rows.children.length > 1) {
          row.remove();
        }
      });
    }

    function addRow() {
      if (!template || !rows) return;
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('.vev-schedule-row');
      rows.appendChild(fragment);
      bindRow(row);
    }

    if (addRowBtn) {
      addRowBtn.addEventListener('click', addRow);
    }

    if (rows && template) {
      addRow();
    }

    if (scheduler) {
      scheduler.addEventListener('submit', function (event) {
        event.preventDefault();
        var appointments = [];
        rows.querySelectorAll('.vev-schedule-row').forEach(function (row) {
          appointments.push({
            creditId: row.querySelector('.vev-credit').value,
            doctorMode: rowDoctorMode(row),
            date: row.querySelector('.vev-date').value,
            time: row.querySelector('.vev-time').value,
            providerId: row.querySelector('.vev-provider').value,
            name: row.querySelector('.vev-name').value.trim(),
            email: row.querySelector('.vev-email').value.trim(),
            phone: row.querySelector('.vev-phone').value.trim()
          });
        });

        if (results) {
          results.textContent = 'Agendando consultas...';
          results.classList.add('is-visible');
        }

        fetch(VEV_PORTAL.ajaxUrl + '?action=vev_schedule_bulk&nonce=' + encodeURIComponent(VEV_PORTAL.nonce), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ appointments: appointments })
        })
          .then(function (res) { return res.json(); })
          .then(function (json) {
            if (!results) return;
            if (!json.success) {
              results.textContent = (json.data && json.data.message) || 'No se pudo agendar.';
              return;
            }

            results.innerHTML = json.data.results.map(function (item, index) {
              var label = 'Fila ' + (index + 1) + ': ' + item.message;
              if (item.meetingUrl) {
                label += ' Link: ' + item.meetingUrl;
              }
              return '<div class="' + (item.success ? 'ok' : 'bad') + '">' + label + '</div>';
            }).join('');
          })
          .catch(function () {
            if (results) results.textContent = 'Error de conexión al agendar.';
          });
      });
    }
  });
})();
