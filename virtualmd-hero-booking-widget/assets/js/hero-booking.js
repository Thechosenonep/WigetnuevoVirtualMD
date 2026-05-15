  document.addEventListener('DOMContentLoaded', function () {
    var VMHB_CONFIG = window.VMHB_CONFIG || {};
    var VM_AJAX_URL = VMHB_CONFIG.ajaxUrl || '/wp-admin/admin-ajax.php';
    var VMHB_ACTIONS = VMHB_CONFIG.actions || {};
    function vmhbAction(name, fallback) { return VMHB_ACTIONS[name] || fallback; }

    // --- Stripe Return Handler ---
    // Si el usuario retorna de Stripe checkout (return_url), verificar pago
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('stripe_return') === '1' && urlParams.get('session_id')) {
      var returnSessionId = urlParams.get('session_id');
      var stripeFlow = urlParams.get('stripe_flow') === 'package' ? 'package' : 'appointment';
      var verifyAction = stripeFlow === 'package'
        ? vmhbAction('stripeVerifyPackagePayment', 'vmhb_stripe_verify_package_payment')
        : vmhbAction('stripeVerifyPayment', 'vmhb_stripe_verify_payment');
      var verifyUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + verifyAction;
      var successTitle = document.getElementById('vmSpeedSuccessTitle');
      var successText = document.getElementById('vmSpeedSuccessText');

      if (stripeFlow === 'package') {
        if (successTitle) successTitle.textContent = 'Procesando compra...';
        if (successText) successText.textContent = 'Estamos confirmando tu pago y registrando el paquete en Amelia.';
      }

      // Mostrar el stage de success directamente
      var bStage = document.getElementById('vmSpeedHeroBookingStage');
      var sStage = document.getElementById('vmSpeedHeroSuccessStage');
      if (bStage && sStage) {
        bStage.classList.add('is-hidden');
        bStage.setAttribute('aria-hidden', 'true');
        sStage.classList.add('is-active');
        sStage.setAttribute('aria-hidden', 'false');
      }

      // Verificar y crear cita en segundo plano
      fetch(verifyUrl, {
        method: 'POST',
        body: JSON.stringify({ session_id: returnSessionId }),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.status === 'complete') {
            if (stripeFlow === 'package') {
              if (successTitle) successTitle.textContent = '¡Paquete comprado correctamente!';
              if (successText) successText.textContent = 'Tu pago fue procesado correctamente y el paquete quedó registrado en Amelia. Recibirás la confirmación en tu correo.';
            }
            console.log('[VM Stripe] Pago verificado exitosamente desde return URL');
          } else {
            if (stripeFlow === 'package') {
              if (successTitle) successTitle.textContent = 'Pago recibido, paquete pendiente';
              if (successText) successText.textContent = (res.data && res.data.message) ? res.data.message : 'No pudimos registrar el paquete automáticamente. Escríbenos con tu comprobante para completarlo.';
            }
            console.warn('[VM Stripe] Estado de pago:', res);
          }
        })
        .catch(function (err) {
          if (stripeFlow === 'package') {
            if (successTitle) successTitle.textContent = 'Pago recibido, paquete pendiente';
            if (successText) successText.textContent = 'No pudimos verificar el paquete automáticamente. Escríbenos con tu comprobante para completarlo.';
          }
          console.error('[VM Stripe] Error al verificar pago:', err);
        });

      // Limpiar URL para evitar re-verificaciones
      if (window.history && window.history.replaceState) {
        var cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
      }

      return; // No inicializar el widget normal si estamos en return mode
    }

    var bookingWidget = document.getElementById('vmSpeedBookingWidget');
    var heroTabs = document.querySelectorAll('.vm-home-tab');
    var heroSubtitle = document.getElementById('vmSpeedHeroSubtitle');
    var aiHelp = document.querySelector('.vm-speed-ai-help');
    var packagePanel = document.getElementById('vmSpeedPackagePanel');
    var packageGrid = document.getElementById('vmSpeedPackageGrid');
    var packageEmpty = document.getElementById('vmSpeedPackageEmpty');
    var doctorModeButtons = document.querySelectorAll('.vm-speed-doctor-mode');
    var heroShell = document.querySelector('.vm-speed-hero-shell');
    var heroStages = document.getElementById('vmSpeedHeroStages');
    var bookingStage = document.getElementById('vmSpeedHeroBookingStage');
    var formStage = document.getElementById('vmSpeedHeroFormStage');
    var successStage = document.getElementById('vmSpeedHeroSuccessStage');
    var backToBookingBtn = document.getElementById('vmSpeedBackToBooking');
    var intakeSummary = document.getElementById('vmSpeedIntakeSummary');
    var submitBookingBtn = document.getElementById('vmSpeedSubmitBooking');


    // Payment stage elements (Stripe)
    var paymentStage = document.getElementById('vmSpeedHeroPaymentStage');
    var backToFormBtn = document.getElementById('vmSpeedBackToForm');
    var stripeCheckoutEl = document.getElementById('vmStripeCheckout');
    var stripeLoadingEl = document.getElementById('vmStripeLoading');
    var stripeErrorEl = document.getElementById('vmStripeError');
    var stripeRetryBtn = document.getElementById('vmStripeRetryBtn');
    var paymentIntro = document.getElementById('vmPaymentIntro');
    var paymentSumServiceLabel = document.getElementById('vmPaymentSummaryServiceLabel');
    var paymentSumDateLabel = document.getElementById('vmPaymentSummaryDateLabel');
    var paymentSumDoctorLabel = document.getElementById('vmPaymentSummaryDoctorLabel');
    var paymentSubtotalRow = document.getElementById('vmPaymentSubtotalRow');
    var paymentSumSubtotalLabel = document.getElementById('vmPaymentSummarySubtotalLabel');
    var paymentSumService = document.getElementById('vmPaymentSummaryService');
    var paymentSumDate = document.getElementById('vmPaymentSummaryDate');
    var paymentSumDoctor = document.getElementById('vmPaymentSummaryDoctor');
    var paymentSumSubtotal = document.getElementById('vmPaymentSummarySubtotal');
    var paymentSumTotal = document.getElementById('vmPaymentSummaryTotal');
    var paymentPackageDiscountRow = document.getElementById('vmPaymentPackageDiscountRow');
    var paymentPackageDiscount = document.getElementById('vmPaymentPackageDiscount');
    var paymentCouponDiscountRow = document.getElementById('vmPaymentCouponDiscountRow');
    var paymentCouponDiscount = document.getElementById('vmPaymentCouponDiscount');
    var packageCouponPanel = document.getElementById('vmSpeedPackageCouponPanel');
    var packageCouponInput = document.getElementById('vmSpeedPackageCouponInput');
    var packageCouponApply = document.getElementById('vmSpeedPackageCouponApply');
    var packageCouponMessage = document.getElementById('vmSpeedPackageCouponMessage');
    var freePackageCheckoutBtn = document.getElementById('vmSpeedFreePackageCheckout');
    var successTitleEl = document.getElementById('vmSpeedSuccessTitle');
    var successTextEl = document.getElementById('vmSpeedSuccessText');

    // Stripe state
    var stripeInstance = null;
    var stripeCheckoutInstance = null;
    var currentSessionId = null;
    var pendingBookingPayload = null;
    var pendingPackageCustomer = null;
    var pendingPackageCoupon = null;
    var pendingPackagePricing = null;
    var pendingPackageAttemptId = null;
    var packageStripeRequestId = 0;
    var packageContentModal = null;

    var intakeName = document.getElementById('vmSpeedIntakeName');
    var intakePhoneCountry = document.getElementById('vmSpeedIntakePhoneCountry');
    var intakePhone = document.getElementById('vmSpeedIntakePhone');
    var intakeEmail = document.getElementById('vmSpeedIntakeEmail');
    var intakeCity = document.getElementById('vmSpeedIntakeCity');
    var intakeMessage = document.getElementById('vmSpeedIntakeMessage');
    var autoDoctorChoice = document.getElementById('vmSpeedAutoDoctorChoice');
    var autoDoctorList = document.getElementById('vmSpeedAutoDoctorList');
    var summaryServiceLabel = document.getElementById('vmSummaryServiceLabel');
    var summaryDateLabel = document.getElementById('vmSummaryDateLabel');
    var summaryDoctorLabel = document.getElementById('vmSummaryDoctorLabel');
    var summaryDoctorValue = document.getElementById('vmSummaryDoctor');
    var doctorDetailModal = null;

    var specialtyField = document.getElementById('vmSpeedSpecialtyField');
    var specialtyToggle = document.getElementById('vmSpeedSpecialtyToggle');
    var specialtyPopover = document.getElementById('vmSpeedSpecialtyPopover');
    var specialtyValue = document.getElementById('vmSpeedSpecialtyValue');
    var categoryList = document.getElementById('vmSpeedCategoryList');
    var serviceList = document.getElementById('vmSpeedServiceList');
    var specialtyGridEl = document.querySelector('.vm-speed-specialty-grid');

    function isMobileView() {
      return window.innerWidth <= 680;
    }

    function getSelectedPhoneCountry() {
      var option = intakePhoneCountry && intakePhoneCountry.options
        ? intakePhoneCountry.options[intakePhoneCountry.selectedIndex]
        : null;

      return {
        iso: option ? option.value : 'mx',
        dialCode: option && option.dataset && Object.prototype.hasOwnProperty.call(option.dataset, 'dial')
          ? option.dataset.dial
          : '+52'
      };
    }

    function normalizePhoneForBooking(rawPhone, phoneCountry) {
      var raw = (rawPhone || '').trim();
      var digits = raw.replace(/\D+/g, '');
      var dialDigits = (phoneCountry.dialCode || '').replace(/\D+/g, '');

      if (!digits) {
        return '';
      }

      if (raw.indexOf('+') === 0) {
        return '+' + digits;
      }

      if (digits.indexOf('00') === 0 && digits.length > 4) {
        return '+' + digits.slice(2);
      }

      if (dialDigits && digits.indexOf(dialDigits) === 0 && digits.length > dialDigits.length + 4) {
        return '+' + digits;
      }

      return '+' + dialDigits + digits;
    }

    function getLocalPhoneDigits(rawPhone, phoneCountry, formattedPhone) {
      var rawDigits = (rawPhone || '').replace(/\D+/g, '');
      var formattedDigits = (formattedPhone || '').replace(/\D+/g, '');
      var dialDigits = (phoneCountry.dialCode || '').replace(/\D+/g, '');

      if (dialDigits && formattedDigits.indexOf(dialDigits) === 0) {
        return formattedDigits.slice(dialDigits.length);
      }

      return rawDigits;
    }

    function isPhoneValidForCountry(rawPhone, phoneCountry, formattedPhone) {
      var localDigits = getLocalPhoneDigits(rawPhone, phoneCountry, formattedPhone);

      if (phoneCountry.iso === 'mx' || phoneCountry.iso === 'us' || phoneCountry.iso === 'ca') {
        return localDigits.length === 10;
      }

      return localDigits.length >= 6 && localDigits.length <= 14;
    }

    function updatePhonePlaceholder() {
      if (!intakePhone || !intakePhoneCountry) return;

      var country = getSelectedPhoneCountry();
      intakePhone.placeholder = !country.dialCode
        ? 'Incluye + lada'
        : (country.iso === 'mx' || country.iso === 'us' || country.iso === 'ca')
        ? '10 dígitos'
        : 'Número local';
    }

    if (intakePhoneCountry) {
      intakePhoneCountry.addEventListener('change', updatePhonePlaceholder);
      updatePhonePlaceholder();
    }

    function showMobileServices() {
      if (specialtyGridEl) specialtyGridEl.classList.add('is-showing-services');
    }

    function showMobileCategories() {
      if (specialtyGridEl) specialtyGridEl.classList.remove('is-showing-services');
    }

    var doctorField = document.getElementById('vmSpeedDoctorField');
    var doctorToggle = document.getElementById('vmSpeedDoctorToggle');
    var doctorPopover = document.getElementById('vmSpeedDoctorPopover');
    var doctorValue = document.getElementById('vmSpeedDoctorValue');
    var doctorList = document.getElementById('vmSpeedDoctorList');

    var dateField = document.getElementById('vmSpeedDateField');
    var dateToggle = document.getElementById('vmSpeedDateToggle');
    var datePopover = document.getElementById('vmSpeedDatePopover');
    var dateValue = document.getElementById('vmSpeedDateValue');
    var calTitle = document.getElementById('vmSpeedCalTitle');
    var calDays = document.getElementById('vmSpeedCalDays');
    var calPrev = document.getElementById('vmSpeedCalPrev');
    var calNext = document.getElementById('vmSpeedCalNext');
    var weekdays = document.getElementById('vmSpeedWeekdays');
    var timeList = document.getElementById('vmSpeedTimeList');
    var bookingCta = document.getElementById('vmSpeedBookingCta');
    var specialtySearch = document.getElementById('vmSpeedSpecialtySearch');
    var doctorSearch = document.getElementById('vmSpeedDoctorSearch');

    if (!bookingWidget || !specialtyField || !dateField || !specialtyToggle || !dateToggle || !bookingCta) {
      return;
    }

    // --- Datos dinámicos (se cargan desde API de Amelia) ---
    var activeBookingFlow = 'appointment';
    var specialtyData = [];
    var doctorData = [];
    var packageData = [];
    var selectedPackage = null;
    var slotsData = {};         // { "2026-03-10": ["09:00","10:00",...], ... } (free)
    var occupiedData = {};      // { "2026-03-10": ["07:00","08:00",...], ... } (taken)
    var providerMapData = {};   // auto mode: { "2026-03-10": { "09:00": [3,5], ... } }
    var slotsPayloadLoaded = false;
    var isLoadingCatalog = false;
    var isLoadingPackages = false;
    var isLoadingDoctors = false;
    var isLoadingSlots = false;
    var doctorCacheByService = {};
    var activeDoctorsRequestId = 0;
    var activeSlotsRequestId = 0;
    var slotsAbortController = null;
    var supportsAbortController = typeof AbortController !== 'undefined';

    var weekLabels = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do'];
    var monthFormatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' });
    var shortDateFormatter = new Intl.DateTimeFormat('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
    var today = new Date();
    var todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    var minCalendarMonth = new Date(todayStart.getFullYear(), todayStart.getMonth(), 1);
    var maxAvailabilityDate = addMonths(todayStart, 3);
    var maxCalendarMonth = new Date(maxAvailabilityDate.getFullYear(), maxAvailabilityDate.getMonth(), 1);

    var calendarMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    var selectedCategoryIndex = 0;
    var doctorMode = 'auto';
    var selectedService = null;
    var selectedDoctor = null;
    var selectedDate = null;
    var selectedTime = null;

    function formatPrice(value) {
      return 'MX$' + Number(value).toFixed(2);
    }

    function computeEndTime(startTime, durationSeconds) {
      var parts = startTime.split(':');
      var h = parseInt(parts[0], 10);
      var m = parseInt(parts[1] || '0', 10);
      var totalMinutes = h * 60 + m + Math.round(durationSeconds / 60);
      var eh = Math.floor(totalMinutes / 60) % 24;
      var em = totalMinutes % 60;
      return (eh < 10 ? '0' : '') + eh + ':' + (em < 10 ? '0' : '') + em;
    }

    function formatTimeRange(startTime) {
      if (!selectedService || !selectedService.duration) return startTime;
      var endTime = computeEndTime(startTime, selectedService.duration);
      return startTime + ' - ' + endTime;
    }

    function escapeHTML(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[char];
      });
    }

    function getPackageIncludedLines(pkg) {
      if (!pkg || !Array.isArray(pkg.services)) {
        return [];
      }

      return pkg.services.map(function (service) {
        var quantity = parseInt(service.quantity || 1, 10);
        var name = service.name || 'Servicio';
        var category = service.category ? ' · ' + service.category : '';
        return (quantity || 1) + ' x ' + name + category;
      });
    }

    function getPackageIncludedText(pkg) {
      var lines = getPackageIncludedLines(pkg);
      return lines.length ? lines.join(' · ') : 'Servicios incluidos en el paquete';
    }

    function packageContentListHTML(pkg) {
      var lines = getPackageIncludedLines(pkg);

      if (!lines.length) {
        return '<p class="vm-speed-package-modal__empty">El contenido se tomará de la configuración del paquete en Amelia.</p>';
      }

      return '<ul class="vm-speed-package-modal__list">' + lines.map(function (line) {
        return '<li>' + escapeHTML(line) + '</li>';
      }).join('') + '</ul>';
    }

    function ensurePackageContentModal() {
      if (packageContentModal) {
        return packageContentModal;
      }

      packageContentModal = document.createElement('div');
      packageContentModal.className = 'vm-speed-package-modal';
      packageContentModal.setAttribute('aria-hidden', 'true');
      packageContentModal.innerHTML =
        '<div class="vm-speed-package-modal__overlay" data-package-modal-close></div>' +
        '<div class="vm-speed-package-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vmPackageModalTitle">' +
          '<button type="button" class="vm-speed-package-modal__close" data-package-modal-close aria-label="Cerrar contenido">×</button>' +
          '<span class="vm-speed-package-modal__eyebrow">Contenido del paquete</span>' +
          '<h3 class="vm-speed-package-modal__title" id="vmPackageModalTitle"></h3>' +
          '<p class="vm-speed-package-modal__description"></p>' +
          '<div class="vm-speed-package-modal__content"></div>' +
        '</div>';

      document.body.appendChild(packageContentModal);

      packageContentModal.addEventListener('click', function (event) {
        if (event.target.closest('[data-package-modal-close]')) {
          closePackageContentModal();
        }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && packageContentModal && packageContentModal.classList.contains('is-open')) {
          closePackageContentModal();
        }
      });

      return packageContentModal;
    }

    function openPackageContentModal(pkg) {
      if (!pkg) {
        return;
      }

      var modal = ensurePackageContentModal();
      var title = modal.querySelector('.vm-speed-package-modal__title');
      var description = modal.querySelector('.vm-speed-package-modal__description');
      var content = modal.querySelector('.vm-speed-package-modal__content');

      if (title) title.textContent = pkg.name || 'Paquete VirtualMD';
      if (description) {
        description.textContent = pkg.description || 'Estas son las consultas incluidas en este paquete.';
        description.hidden = !description.textContent;
      }
      if (content) content.innerHTML = packageContentListHTML(pkg);

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('vm-speed-modal-open');
    }

    function closePackageContentModal() {
      if (!packageContentModal) {
        return;
      }

      packageContentModal.classList.remove('is-open');
      packageContentModal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('vm-speed-modal-open');
    }

    function getPackagePrice(pkg) {
      return pkg && pkg.price ? Number(pkg.price) : 0;
    }

    function getPackageBasePrice(pkg) {
      return pkg && pkg.basePrice ? Number(pkg.basePrice) : getPackagePrice(pkg);
    }

    function getPackagePricing() {
      if (pendingPackagePricing) {
        return pendingPackagePricing;
      }

      if (!selectedPackage) {
        return null;
      }

      return {
        basePrice: getPackageBasePrice(selectedPackage),
        packageDiscountPercent: Number(selectedPackage.packageDiscountPercent || 0),
        packageDiscountAmount: Number(selectedPackage.packageDiscountAmount || 0),
        subtotal: getPackagePrice(selectedPackage),
        coupon: null,
        couponId: 0,
        couponCode: '',
        couponDiscountAmount: 0,
        total: getPackagePrice(selectedPackage)
      };
    }

    function getAppointmentPricing() {
      if (pendingPackagePricing) {
        return pendingPackagePricing;
      }

      if (!selectedService) {
        return null;
      }

      var basePrice = Number(selectedService.price || 0);
      return {
        basePrice: basePrice,
        packageDiscountPercent: 0,
        packageDiscountAmount: 0,
        subtotal: basePrice,
        coupon: null,
        couponId: 0,
        couponCode: '',
        couponDiscountAmount: 0,
        total: basePrice
      };
    }

    function getActivePricing() {
      return activeBookingFlow === 'package' ? getPackagePricing() : getAppointmentPricing();
    }

    function resetPackageCouponState() {
      pendingPackageCoupon = null;
      pendingPackagePricing = null;
      if (packageCouponInput) packageCouponInput.value = '';
      if (packageCouponMessage) {
        packageCouponMessage.textContent = '';
        packageCouponMessage.classList.remove('is-error', 'is-success');
      }
    }

    function makePackageAttemptId() {
      return 'pkg_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    }

    function setSuccessCopy(flow) {
      if (flow === 'package') {
        if (successTitleEl) successTitleEl.textContent = '¡Paquete comprado correctamente!';
        if (successTextEl) successTextEl.textContent = 'Tu pago fue procesado correctamente y el paquete quedó registrado en Amelia. Podrás agendar sus consultas incluidas desde tu cuenta.';
        return;
      }

      if (successTitleEl) successTitleEl.textContent = '¡Pago exitoso y cita agendada!';
      if (successTextEl) successTextEl.textContent = 'Tu pago fue procesado correctamente y tu cita ha sido confirmada. Recibirás un email de confirmación en unos momentos.';
    }

    function getDoctorName(doctor) {
      if (!doctor) {
        return '';
      }
      return (doctor.name || doctor.nombre_completo || '').trim();
    }

    function getDoctorImage(doctor) {
      return doctor ? (doctor.image || doctor.imagen || '').trim() : '';
    }

    function getDoctorTeamMember(doctor) {
      if (!doctor) {
        return {};
      }
      return doctor.teamMember || doctor.team_member || {};
    }

    function doctorInitials(doctor) {
      var name = getDoctorName(doctor);
      var parts = name.replace(/^(Dr\.?|Dra\.?|Lic\.?|Mtro\.?)\s+/i, '').split(/\s+/).filter(Boolean);
      if (!parts.length) {
        return 'MD';
      }
      if (parts.length === 1) {
        return parts[0].charAt(0).toUpperCase();
      }
      return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    function doctorAvatarHTML(doctor, className) {
      var image = getDoctorImage(doctor);
      var name = getDoctorName(doctor) || 'Doctor';
      if (image) {
        return '<span class="' + className + '"><img src="' + escapeHTML(image) + '" alt="' + escapeHTML(name) + '" loading="lazy"></span>';
      }
      return '<span class="' + className + '"><span>' + escapeHTML(doctorInitials(doctor)) + '</span></span>';
    }

    function doctorSpecialtiesHTML(doctor) {
      var specialties = doctor && (doctor.specialties || doctor.especialidades) ? (doctor.specialties || doctor.especialidades) : [];
      if (!Array.isArray(specialties) || !specialties.length) {
        return '';
      }
      return specialties.slice(0, 6).map(function (item) {
        return '<span class="vm-speed-detail-pill">' + escapeHTML(item) + '</span>';
      }).join('');
    }

    function ensureDoctorDetailModal() {
      if (doctorDetailModal) {
        return doctorDetailModal;
      }

      doctorDetailModal = document.createElement('div');
      doctorDetailModal.className = 'vm-speed-doctor-detail';
      doctorDetailModal.setAttribute('aria-hidden', 'true');
      doctorDetailModal.innerHTML =
        '<div class="vm-speed-doctor-detail__backdrop" data-vm-doctor-detail-close></div>' +
        '<article class="vm-detail-card vm-speed-doctor-detail__card" role="dialog" aria-modal="true" aria-labelledby="vmSpeedDoctorDetailName">' +
          '<button type="button" class="vm-detail-close vm-speed-doctor-detail__close" data-vm-doctor-detail-close>Regresar</button>' +
          '<div class="vm-detail-content">' +
            '<div class="vm-detail-top">' +
              '<div class="vm-detail-avatar"></div>' +
              '<div class="vm-detail-texts">' +
                '<h3 class="vm-detail-name" id="vmSpeedDoctorDetailName"></h3>' +
                '<p class="vm-detail-job"></p>' +
                '<div class="vm-detail-specialties"></div>' +
              '</div>' +
            '</div>' +
            '<div class="vm-detail-body"></div>' +
            '<div class="vm-detail-actions">' +
              '<a class="vm-detail-link" href="#" target="_blank" rel="noopener noreferrer">Ver perfil completo</a>' +
            '</div>' +
          '</div>' +
        '</article>';

      doctorDetailModal.addEventListener('click', function (event) {
        if (event.target && event.target.hasAttribute('data-vm-doctor-detail-close')) {
          closeDoctorDetail();
        }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && doctorDetailModal && doctorDetailModal.classList.contains('is-active')) {
          closeDoctorDetail();
        }
      });

      document.body.appendChild(doctorDetailModal);
      return doctorDetailModal;
    }

    function closeDoctorDetail() {
      if (!doctorDetailModal) {
        return;
      }
      doctorDetailModal.classList.remove('is-active');
      doctorDetailModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('vm-speed-doctor-detail-open');
    }

    function openDoctorDetail(doctor) {
      if (!doctor) {
        return;
      }

      var modal = ensureDoctorDetailModal();
      var teamMember = getDoctorTeamMember(doctor);
      var name = getDoctorName(doctor) || 'Médico sin nombre';
      var image = getDoctorImage(doctor);
      var avatarNode = modal.querySelector('.vm-detail-avatar');
      var nameNode = modal.querySelector('.vm-detail-name');
      var jobNode = modal.querySelector('.vm-detail-job');
      var specsNode = modal.querySelector('.vm-detail-specialties');
      var bodyNode = modal.querySelector('.vm-detail-body');
      var linkNode = modal.querySelector('.vm-detail-link');

      if (avatarNode) {
        avatarNode.innerHTML = image
          ? '<img src="' + escapeHTML(image) + '" alt="' + escapeHTML(name) + '" loading="lazy">'
          : '<span>' + escapeHTML(doctorInitials(doctor)) + '</span>';
      }
      if (nameNode) {
        nameNode.textContent = name;
      }
      if (jobNode) {
        var cargo = teamMember && teamMember.cargo ? String(teamMember.cargo).trim() : '';
        jobNode.textContent = cargo ? cargo.toLocaleLowerCase('es-MX') : '';
        jobNode.style.display = cargo ? '' : 'none';
      }
      if (specsNode) {
        specsNode.innerHTML = doctorSpecialtiesHTML(doctor);
        specsNode.style.display = specsNode.innerHTML ? '' : 'none';
      }
      if (bodyNode) {
        var content = teamMember && teamMember.contenido ? teamMember.contenido : '';
        var summary = teamMember && teamMember.resumen ? teamMember.resumen : '';
        if (content) {
          bodyNode.innerHTML = content;
        } else if (summary) {
          bodyNode.textContent = summary;
        } else {
          bodyNode.textContent = 'Perfil del especialista disponible próximamente.';
        }
      }
      if (linkNode) {
        var link = (teamMember && teamMember.enlace) || doctor.profileUrl || doctor.url || '';
        if (link && link !== '#') {
          linkNode.href = link;
          linkNode.style.display = '';
        } else {
          linkNode.removeAttribute('href');
          linkNode.style.display = 'none';
        }
      }

      modal.classList.add('is-active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('vm-speed-doctor-detail-open');
    }

    function isSameDate(a, b) {
      return a && b &&
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate();
    }

    function addMonths(date, months) {
      var result = new Date(date.getTime());
      var originalDay = result.getDate();
      result.setMonth(result.getMonth() + months);
      if (result.getDate() !== originalDay) {
        result.setDate(0);
      }
      return new Date(result.getFullYear(), result.getMonth(), result.getDate());
    }

    function isBeforeMonth(a, b) {
      return a.getFullYear() < b.getFullYear() ||
        (a.getFullYear() === b.getFullYear() && a.getMonth() < b.getMonth());
    }

    function isAfterMonth(a, b) {
      return a.getFullYear() > b.getFullYear() ||
        (a.getFullYear() === b.getFullYear() && a.getMonth() > b.getMonth());
    }

    function clampCalendarMonth() {
      if (isBeforeMonth(calendarMonth, minCalendarMonth)) {
        calendarMonth = new Date(minCalendarMonth);
      } else if (isAfterMonth(calendarMonth, maxCalendarMonth)) {
        calendarMonth = new Date(maxCalendarMonth);
      }
    }

    function initializeDefaults() {
      selectedService = null;
      selectedDate = null;
      selectedTime = null;
      selectedDoctor = null;
    }

    function hasAllSelections() {
      var hasDoctorSelection = doctorMode !== 'manual' || !!selectedDoctor;
      return !!selectedService && !!selectedDate && !!selectedTime && hasDoctorSelection;
    }

    function renderPackageCards() {
      if (!packageGrid) return;

      packageGrid.innerHTML = '';

      if (packageEmpty) {
        packageEmpty.hidden = packageData.length > 0;
      }

      packageData.forEach(function (pkg) {
        var isActive = selectedPackage && parseInt(selectedPackage.id, 10) === parseInt(pkg.id, 10);
        var card = document.createElement('article');
        card.className = 'vm-speed-package-card' + (isActive ? ' is-active' : '');
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-pressed', isActive ? 'true' : 'false');

        var includedLines = getPackageIncludedLines(pkg);
        var basePrice = getPackageBasePrice(pkg);
        var finalPrice = getPackagePrice(pkg);
        var packageDiscountPercent = Number(pkg.packageDiscountPercent || 0);
        var priceHtml = packageDiscountPercent > 0 && basePrice > finalPrice
          ? '<span class="vm-speed-package-card__price-old">' + formatPrice(basePrice) + '</span>' +
            '<span class="vm-speed-package-card__price-discount">-' + packageDiscountPercent.toFixed(packageDiscountPercent % 1 === 0 ? 0 : 1) + '%</span>' +
            '<span class="vm-speed-package-card__price-final">' + formatPrice(finalPrice) + '</span>'
          : '<span class="vm-speed-package-card__price-final">' + formatPrice(finalPrice) + '</span>';
        var discountLabel = packageDiscountPercent > 0
          ? packageDiscountPercent.toFixed(packageDiscountPercent % 1 === 0 ? 0 : 1) + '% Descuento'
          : 'Paquete VirtualMD';
        var packageDescription = pkg.description || 'Paquete de consultas VirtualMD';

        card.innerHTML =
          '<span class="vm-speed-package-card__header">' +
            '<span class="vm-speed-package-card__title">' + escapeHTML(pkg.name || 'Paquete VirtualMD') + '</span>' +
            '<span class="vm-speed-package-card__badge">' + escapeHTML(discountLabel) + '</span>' +
          '</span>' +
          '<span class="vm-speed-package-card__body">' +
            '<span class="vm-speed-package-card__description">' + escapeHTML(packageDescription) + '</span>' +
            '<span class="vm-speed-package-card__price">' + priceHtml + '</span>' +
            '<span class="vm-speed-package-card__validity">' + escapeHTML(pkg.validityLabel || 'Vigencia según configuración de Amelia') + '</span>' +
            '<button type="button" class="vm-speed-package-card__details-toggle">Ver contenido del paquete</button>' +
          '</span>' +
          '<span class="vm-speed-package-card__footer">' +
            '<button type="button" class="vm-speed-package-card__cta">Adquirir paquete</button>' +
          '</span>';

        function selectPackage() {
          selectedPackage = pkg;
          pendingPackageAttemptId = null;
          resetPackageCouponState();
          renderPackageCards();
          updateIntakeSummary();
          updatePaymentSummary();
        }

        card.addEventListener('click', function (event) {
          if (event.target.closest('.vm-speed-package-card__details-toggle')) {
            return;
          }
          if (event.target.closest('.vm-speed-package-card__cta')) {
            return;
          }
          selectPackage();
        });

        card.addEventListener('keydown', function (event) {
          if (event.target.closest('button')) {
            return;
          }
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectPackage();
          }
        });

        var detailsToggle = card.querySelector('.vm-speed-package-card__details-toggle');
        if (detailsToggle) {
          detailsToggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openPackageContentModal(pkg);
          });
        }

        var acquireButton = card.querySelector('.vm-speed-package-card__cta');
        if (acquireButton) {
          acquireButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            selectPackage();
            showIntakeStep();
          });
        }

        packageGrid.appendChild(card);
      });
    }

    function loadPackages() {
      if (isLoadingPackages || packageData.length) {
        renderPackageCards();
        return;
      }

      isLoadingPackages = true;
      if (packageGrid) {
        packageGrid.innerHTML = '<div class="vm-speed-package-loading"><span class="vm-speed-spinner"></span> Cargando paquetes...</div>';
      }
      if (packageEmpty) packageEmpty.hidden = true;

      var url = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + vmhbAction('packages', 'vmhb_amelia_get_packages');

      fetch(url, { method: 'GET' })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && Array.isArray(res.data.packages)) {
            packageData = res.data.packages;
            if (!selectedPackage && packageData.length) {
              selectedPackage = packageData[0];
            }
            renderPackageCards();
          } else {
            packageData = [];
            if (packageGrid) packageGrid.innerHTML = '';
            if (packageEmpty) {
              packageEmpty.textContent = 'No pudimos cargar los paquetes disponibles.';
              packageEmpty.hidden = false;
            }
          }
        })
        .catch(function () {
          packageData = [];
          if (packageGrid) packageGrid.innerHTML = '';
          if (packageEmpty) {
            packageEmpty.textContent = 'No pudimos cargar los paquetes disponibles.';
            packageEmpty.hidden = false;
          }
        })
        .finally(function () {
          isLoadingPackages = false;
        });
    }

    function setBookingFlow(flow) {
      activeBookingFlow = flow === 'package' ? 'package' : 'appointment';
      closeAllFields(null);
      setSuccessCopy(activeBookingFlow);

      heroTabs.forEach(function (tab) {
        var active = tab.dataset.tab === (activeBookingFlow === 'package' ? 'paquetes' : 'consulta');
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      if (activeBookingFlow === 'package') {
        if (heroSubtitle) heroSubtitle.textContent = 'Selecciona un paquete y completa tus datos para comprarlo.';
        if (bookingWidget) bookingWidget.hidden = true;
        if (packagePanel) packagePanel.hidden = false;
        if (aiHelp) aiHelp.hidden = true;
        var doctorPref = document.querySelector('.vm-speed-doctor-pref');
        if (doctorPref) doctorPref.hidden = true;
        loadPackages();
        updateIntakeSummary();
        updatePaymentSummary();
        return;
      }

      if (heroSubtitle) heroSubtitle.textContent = 'Selecciona especialidad, fecha y horario.';
      if (bookingWidget) bookingWidget.hidden = false;
      if (packagePanel) packagePanel.hidden = true;
      if (aiHelp) aiHelp.hidden = false;
      var pref = document.querySelector('.vm-speed-doctor-pref');
      if (pref) pref.hidden = false;
      updateIntakeSummary();
      updatePaymentSummary();
    }

    function getDoctorDataById(id) {
      id = parseInt(id, 10);
      for (var i = 0; i < doctorData.length; i++) {
        if (parseInt(doctorData[i].id, 10) === id) {
          return doctorData[i];
        }
      }
      return null;
    }

    function findDoctorById(id) {
      id = parseInt(id, 10);
      var doctor = getDoctorDataById(id);
      if (doctor) {
        return doctor;
      }
      return { id: id, name: 'Doctor #' + id, meta: '' };
    }

    function hydrateSelectedDoctorFromData() {
      if (!selectedDoctor || !selectedDoctor.id) {
        return;
      }
      var doctor = getDoctorDataById(selectedDoctor.id);
      if (doctor) {
        selectedDoctor = doctor;
        updateDoctorValue();
      }
    }

    function getCurrentAutoProviderIds() {
      if (doctorMode === 'manual' || !selectedDate || !selectedTime || !providerMapData) {
        return [];
      }
      var dk = formatDateKey(selectedDate);
      var dayMap = providerMapData[dk] || {};
      var providers = dayMap[selectedTime];

      if (!providers) {
        Object.keys(dayMap).some(function (key) {
          if (String(key).substring(0, 5) === selectedTime) {
            providers = dayMap[key];
            return true;
          }
          return false;
        });
      }

      if (!providers) {
        return [];
      }

      if (!Array.isArray(providers) && typeof providers === 'object') {
        providers = Object.keys(providers).map(function (key) {
          return providers[key];
        });
      }

      if (!Array.isArray(providers) || !providers.length) {
        return [];
      }

      return providers.map(function (id) {
        return parseInt(id, 10);
      }).filter(function (id, index, all) {
        return !!id && all.indexOf(id) === index;
      });
    }

    function setAutoDoctorFromId(id) {
      selectedDoctor = findDoctorById(id);
      updateDoctorValue();
      updateIntakeSummary();
      updatePaymentSummary();
      renderAutoDoctorChoice();
    }

    function syncAutoDoctorForSelectedTime() {
      if (doctorMode === 'manual') {
        return;
      }

      var providers = getCurrentAutoProviderIds();
      if (!providers.length) {
        selectedDoctor = null;
        updateDoctorValue();
        return;
      }

      if (providers.length === 1) {
        selectedDoctor = findDoctorById(providers[0]);
        updateDoctorValue();
        return;
      }

      var currentId = selectedDoctor ? parseInt(selectedDoctor.id, 10) : 0;
      if (!currentId || providers.indexOf(currentId) === -1) {
        selectedDoctor = null;
      }
      updateDoctorValue();
    }

    function renderAutoDoctorChoice() {
      if (!autoDoctorChoice || !autoDoctorList) {
        return;
      }

      var providers = getCurrentAutoProviderIds();
      autoDoctorList.innerHTML = '';

      if (doctorMode === 'manual' || providers.length <= 1) {
        autoDoctorChoice.hidden = true;
        return;
      }

      autoDoctorChoice.hidden = false;

      providers.forEach(function (providerId) {
        var doctor = findDoctorById(providerId);
        var active = selectedDoctor && parseInt(selectedDoctor.id, 10) === providerId;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'vm-speed-auto-doctor-option' + (active ? ' is-active' : '');
        button.innerHTML =
          doctorAvatarHTML(doctor, 'vm-speed-auto-doctor-option__avatar') +
          '<span class="vm-speed-auto-doctor-option__name">' + escapeHTML(getDoctorName(doctor)) + '</span>';
        button.addEventListener('click', function () {
          setAutoDoctorFromId(providerId);
        });
        autoDoctorList.appendChild(button);
      });
    }

    function updateIntakeSummary() {
      var svcEl = document.getElementById('vmSummaryService');
      var dateEl = document.getElementById('vmSummaryDate');
      var docEl = document.getElementById('vmSummaryDoctor');
      if (!svcEl || !dateEl || !docEl) return;

      if (activeBookingFlow === 'package') {
        if (summaryServiceLabel) summaryServiceLabel.textContent = 'Paquete';
        if (summaryDateLabel) summaryDateLabel.textContent = 'Incluye';
        if (summaryDoctorLabel) summaryDoctorLabel.textContent = 'Uso';

        svcEl.textContent = selectedPackage
          ? selectedPackage.name + ' (' + formatPrice(getPackagePrice(selectedPackage)) + ')'
          : 'Selecciona un paquete';
        dateEl.innerHTML = selectedPackage
          ? '<button type="button" class="vm-speed-summary-package-content">Ver contenido del paquete</button>'
          : '—';
        docEl.textContent = 'Agenda las consultas incluidas después de comprar';
        docEl.classList.remove('vm-speed-intake-summary__value--doctor');
        docEl.removeAttribute('role');
        docEl.removeAttribute('tabindex');
        docEl.removeAttribute('aria-label');

        if (autoDoctorChoice) autoDoctorChoice.hidden = true;
        return;
      }

      if (summaryServiceLabel) summaryServiceLabel.textContent = 'Consulta';
      if (summaryDateLabel) summaryDateLabel.textContent = 'Día de la consulta';
      if (summaryDoctorLabel) summaryDoctorLabel.textContent = 'Especialista';

      // Consulta
      if (selectedService) {
        svcEl.textContent = selectedService.category + ' — ' + selectedService.type + ' (' + formatPrice(selectedService.price) + ')';
      } else {
        svcEl.textContent = '—';
      }

      // Día/hora
      if (selectedDate && selectedTime) {
        var dayFormatted = new Intl.DateTimeFormat('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(selectedDate);
        dateEl.textContent = dayFormatted.charAt(0).toUpperCase() + dayFormatted.slice(1) + ' · ' + formatTimeRange(selectedTime) + ' hrs';
      } else {
        dateEl.textContent = '—';
      }

      // Doctor
      if (selectedDoctor && selectedDoctor.name) {
        docEl.textContent = selectedDoctor.name;
        docEl.classList.add('vm-speed-intake-summary__value--doctor');
        docEl.setAttribute('role', 'button');
        docEl.setAttribute('tabindex', '0');
        docEl.setAttribute('aria-label', 'Ver perfil de ' + selectedDoctor.name);
      } else if (getCurrentAutoProviderIds().length > 1) {
        docEl.textContent = 'Elige especialista';
        docEl.classList.remove('vm-speed-intake-summary__value--doctor');
        docEl.removeAttribute('role');
        docEl.removeAttribute('tabindex');
        docEl.removeAttribute('aria-label');
      } else {
        docEl.textContent = 'Por asignar';
        docEl.classList.remove('vm-speed-intake-summary__value--doctor');
        docEl.removeAttribute('role');
        docEl.removeAttribute('tabindex');
        docEl.removeAttribute('aria-label');
      }

      renderAutoDoctorChoice();
    }

    if (summaryDoctorValue) {
      summaryDoctorValue.addEventListener('click', function () {
        if (activeBookingFlow === 'appointment' && selectedDoctor && selectedDoctor.name) {
          openDoctorDetail(selectedDoctor);
        }
      });
      summaryDoctorValue.addEventListener('keydown', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && activeBookingFlow === 'appointment' && selectedDoctor && selectedDoctor.name) {
          event.preventDefault();
          openDoctorDetail(selectedDoctor);
        }
      });
    }


    function showIntakeStep() {
      if (!bookingStage || !formStage) {
        return;
      }
      closeAllFields(null);
      if (activeBookingFlow === 'appointment' && doctorMode !== 'manual') {
        syncAutoDoctorForSelectedTime();
        if (selectedService && !doctorData.length) {
          loadDoctors(selectedService.id);
        }
      }
      updateIntakeSummary();
      if (activeBookingFlow === 'appointment') {
        renderAutoDoctorChoice();
      }

      // Slide-out booking stage
      heroStages.classList.add('is-animating');
      bookingStage.classList.add('is-sliding-out');

      function onBookingSlideOutEnd() {
        bookingStage.removeEventListener('animationend', onBookingSlideOutEnd);
        bookingStage.classList.remove('is-sliding-out');
        bookingStage.classList.add('is-hidden');
        bookingStage.setAttribute('aria-hidden', 'true');

        // Slide-in form stage
        formStage.classList.remove('is-hidden');
        formStage.classList.add('is-active');
        formStage.classList.add('is-sliding-in');
        formStage.setAttribute('aria-hidden', 'false');

        function onFormSlideInEnd() {
          formStage.removeEventListener('animationend', onFormSlideInEnd);
          formStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
          renderAutoDoctorChoice();
        }
        formStage.addEventListener('animationend', onFormSlideInEnd);
      }
      bookingStage.addEventListener('animationend', onBookingSlideOutEnd);
    }

    function showBookingStep() {
      if (!bookingStage || !formStage) {
        return;
      }

      // Slide-out form stage
      heroStages.classList.add('is-animating');
      formStage.classList.add('is-sliding-out');

      function onFormSlideOutEnd() {
        formStage.removeEventListener('animationend', onFormSlideOutEnd);
        formStage.classList.remove('is-sliding-out');
        formStage.classList.remove('is-active');
        formStage.classList.add('is-hidden');
        formStage.setAttribute('aria-hidden', 'true');

        // Slide-in booking stage
        bookingStage.classList.remove('is-hidden');
        bookingStage.classList.add('is-sliding-in');
        bookingStage.setAttribute('aria-hidden', 'false');

        function onBookingSlideInEnd() {
          bookingStage.removeEventListener('animationend', onBookingSlideInEnd);
          bookingStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
        }
        bookingStage.addEventListener('animationend', onBookingSlideInEnd);
      }
      formStage.addEventListener('animationend', onFormSlideOutEnd);
    }

    function showSuccessStep() {
      if (!formStage || !successStage) return;
      heroStages.classList.add('is-animating');
      formStage.classList.add('is-sliding-out');

      function onFormOutEnd() {
        formStage.removeEventListener('animationend', onFormOutEnd);
        formStage.classList.remove('is-sliding-out');
        formStage.classList.remove('is-active');
        formStage.classList.add('is-hidden');
        formStage.setAttribute('aria-hidden', 'true');

        successStage.classList.add('is-active');
        successStage.classList.add('is-sliding-in');
        successStage.setAttribute('aria-hidden', 'false');

        function onSuccessInEnd() {
          successStage.removeEventListener('animationend', onSuccessInEnd);
          successStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
        }
        successStage.addEventListener('animationend', onSuccessInEnd);
      }
      formStage.addEventListener('animationend', onFormOutEnd);
    }

    // --- TRANSICIONES PARA STAGE DE PAGO ---

    function showPaymentStep() {
      if (!formStage || !paymentStage) return;
      heroStages.classList.add('is-animating');
      formStage.classList.add('is-sliding-out');

      function onFormOutEnd() {
        formStage.removeEventListener('animationend', onFormOutEnd);
        formStage.classList.remove('is-sliding-out');
        formStage.classList.remove('is-active');
        formStage.classList.add('is-hidden');
        formStage.setAttribute('aria-hidden', 'true');

        paymentStage.classList.remove('is-hidden');
        paymentStage.classList.add('is-active');
        paymentStage.classList.add('is-sliding-in');
        paymentStage.setAttribute('aria-hidden', 'false');

        function onPaymentInEnd() {
          paymentStage.removeEventListener('animationend', onPaymentInEnd);
          paymentStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
        }
        paymentStage.addEventListener('animationend', onPaymentInEnd);
      }
      formStage.addEventListener('animationend', onFormOutEnd);
    }

    function showFormFromPayment() {
      if (!paymentStage || !formStage) return;
      // Destruir Stripe Checkout si existe
      if (stripeCheckoutInstance) {
        stripeCheckoutInstance.destroy();
        stripeCheckoutInstance = null;
      }
      heroStages.classList.add('is-animating');
      paymentStage.classList.add('is-sliding-out');

      function onPaymentOutEnd() {
        paymentStage.removeEventListener('animationend', onPaymentOutEnd);
        paymentStage.classList.remove('is-sliding-out');
        paymentStage.classList.remove('is-active');
        paymentStage.classList.add('is-hidden');
        paymentStage.setAttribute('aria-hidden', 'true');

        formStage.classList.remove('is-hidden');
        formStage.classList.add('is-active');
        formStage.classList.add('is-sliding-in');
        formStage.setAttribute('aria-hidden', 'false');

        function onFormInEnd() {
          formStage.removeEventListener('animationend', onFormInEnd);
          formStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
        }
        formStage.addEventListener('animationend', onFormInEnd);
      }
      paymentStage.addEventListener('animationend', onPaymentOutEnd);
    }

    function showSuccessFromPayment() {
      if (!paymentStage || !successStage) return;
      setSuccessCopy(activeBookingFlow);
      if (activeBookingFlow === 'appointment') {
        clearAvailabilityState();
      }
      // Destruir Stripe Checkout si existe
      if (stripeCheckoutInstance) {
        stripeCheckoutInstance.destroy();
        stripeCheckoutInstance = null;
      }
      heroStages.classList.add('is-animating');
      paymentStage.classList.add('is-sliding-out');

      function onPaymentOutEnd() {
        paymentStage.removeEventListener('animationend', onPaymentOutEnd);
        paymentStage.classList.remove('is-sliding-out');
        paymentStage.classList.remove('is-active');
        paymentStage.classList.add('is-hidden');
        paymentStage.setAttribute('aria-hidden', 'true');

        successStage.classList.add('is-active');
        successStage.classList.add('is-sliding-in');
        successStage.setAttribute('aria-hidden', 'false');

        function onSuccessInEnd() {
          successStage.removeEventListener('animationend', onSuccessInEnd);
          successStage.classList.remove('is-sliding-in');
          heroStages.classList.remove('is-animating');
        }
        successStage.addEventListener('animationend', onSuccessInEnd);
      }
      paymentStage.addEventListener('animationend', onPaymentOutEnd);
    }

    function updatePaymentSummary() {
      if (activeBookingFlow === 'package') {
        var pricing = getPackagePricing();
        if (paymentIntro) paymentIntro.textContent = 'Tu paquete se registrará en Amelia automáticamente al procesar el pago de forma segura.';
        if (paymentSumServiceLabel) paymentSumServiceLabel.textContent = 'Paquete';
        if (paymentSumDateLabel) paymentSumDateLabel.textContent = 'Incluye';
        if (paymentSumDoctorLabel) paymentSumDoctorLabel.textContent = 'Uso';
        if (paymentSubtotalRow) paymentSubtotalRow.hidden = false;
        if (paymentSumSubtotalLabel) paymentSumSubtotalLabel.textContent = 'Subtotal';
        if (paymentSumService) paymentSumService.textContent = selectedPackage ? selectedPackage.name : '—';
        if (paymentSumDate) paymentSumDate.textContent = selectedPackage ? getPackageIncludedText(selectedPackage) : '—';
        if (paymentSumDoctor) paymentSumDoctor.textContent = 'Consultas para agendar después de la compra';
        if (paymentSumSubtotal) paymentSumSubtotal.textContent = pricing ? formatPrice(pricing.basePrice) + ' MXN' : '—';
        if (paymentPackageDiscountRow) paymentPackageDiscountRow.hidden = !(pricing && Number(pricing.packageDiscountAmount) > 0);
        if (paymentPackageDiscount) {
          var pct = pricing ? Number(pricing.packageDiscountPercent || 0) : 0;
          paymentPackageDiscount.textContent = pricing && Number(pricing.packageDiscountAmount) > 0
            ? '-' + formatPrice(pricing.packageDiscountAmount) + (pct ? ' (' + pct.toFixed(pct % 1 === 0 ? 0 : 1) + '%)' : '')
            : '—';
        }
        if (paymentCouponDiscountRow) paymentCouponDiscountRow.hidden = !(pricing && Number(pricing.couponDiscountAmount) > 0);
        if (paymentCouponDiscount) {
          paymentCouponDiscount.textContent = pricing && Number(pricing.couponDiscountAmount) > 0
            ? '-' + formatPrice(pricing.couponDiscountAmount) + (pricing.couponCode ? ' · ' + pricing.couponCode : '')
            : '—';
        }
        if (paymentSumTotal) paymentSumTotal.textContent = pricing ? formatPrice(pricing.total) + ' MXN' : '—';
        if (packageCouponPanel) packageCouponPanel.hidden = false;
        syncPackagePaymentControls();
        return;
      }

      if (paymentIntro) paymentIntro.textContent = 'Tu cita se confirmará automáticamente al procesar el pago de forma segura.';
      var appointmentPricing = getAppointmentPricing();
      if (paymentSumServiceLabel) paymentSumServiceLabel.textContent = 'Consulta';
      if (paymentSumDateLabel) paymentSumDateLabel.textContent = 'Fecha';
      if (paymentSumDoctorLabel) paymentSumDoctorLabel.textContent = 'Especialista';
      if (paymentSubtotalRow) paymentSubtotalRow.hidden = !(appointmentPricing && Number(appointmentPricing.couponDiscountAmount) > 0);
      if (paymentSumSubtotalLabel) paymentSumSubtotalLabel.textContent = 'Subtotal';
      if (paymentPackageDiscountRow) paymentPackageDiscountRow.hidden = true;
      if (paymentCouponDiscountRow) paymentCouponDiscountRow.hidden = !(appointmentPricing && Number(appointmentPricing.couponDiscountAmount) > 0);
      if (paymentCouponDiscount) {
        paymentCouponDiscount.textContent = appointmentPricing && Number(appointmentPricing.couponDiscountAmount) > 0
          ? '-' + formatPrice(appointmentPricing.couponDiscountAmount) + (appointmentPricing.couponCode ? ' · ' + appointmentPricing.couponCode : '')
          : '—';
      }
      if (packageCouponPanel) packageCouponPanel.hidden = false;
      if (paymentSumService && selectedService) {
        paymentSumService.textContent = selectedService.category + ' — ' + selectedService.type;
      }
      if (paymentSumDate && selectedDate && selectedTime) {
        var dayFormatted = new Intl.DateTimeFormat('es-MX', { weekday: 'long', day: 'numeric', month: 'long' }).format(selectedDate);
        paymentSumDate.textContent = dayFormatted.charAt(0).toUpperCase() + dayFormatted.slice(1) + ' · ' + formatTimeRange(selectedTime) + ' hrs';
      }
      if (paymentSumDoctor) {
        paymentSumDoctor.textContent = selectedDoctor && selectedDoctor.name ? selectedDoctor.name : 'Por asignar';
      }
      if (paymentSumSubtotal && selectedService) {
        paymentSumSubtotal.textContent = formatPrice(selectedService.price) + ' MXN';
      }
      if (paymentSumTotal && selectedService) {
        paymentSumTotal.textContent = appointmentPricing ? formatPrice(appointmentPricing.total) + ' MXN' : formatPrice(selectedService.price) + ' MXN';
      }
      syncPackagePaymentControls();
    }

    function initStripeCheckout(clientSecret) {
      if (!stripeCheckoutEl) return;

      // Verificar que Stripe.js esté cargado y la config exista
      if (typeof Stripe === 'undefined') {
        console.error('[VM Stripe] Stripe.js no está cargado');
        showStripeError('Stripe.js no pudo cargarse. Recarga la página.');
        return;
      }

      var publicKey = (typeof vmStripeConfig !== 'undefined' && vmStripeConfig.publicKey)
        ? vmStripeConfig.publicKey
        : (VMHB_CONFIG.stripePublicKey || '');

      if (!publicKey) {
        console.error('[VM Stripe] No se encontró la clave pública de Stripe');
        showStripeError('Configuración de pago incompleta.');
        return;
      }

      // Inicializar Stripe si no se ha hecho
      if (!stripeInstance) {
        stripeInstance = Stripe(publicKey);
      }

      // Mostrar loading
      if (stripeLoadingEl) stripeLoadingEl.style.display = 'flex';
      if (stripeErrorEl) stripeErrorEl.classList.remove('is-visible');
      stripeCheckoutEl.innerHTML = '';

      stripeInstance.createEmbeddedCheckoutPage({
        clientSecret: clientSecret,
      }).then(function (checkout) {
        stripeCheckoutInstance = checkout;
        // Ocultar loading y montar
        if (stripeLoadingEl) stripeLoadingEl.style.display = 'none';
        checkout.mount('#vmStripeCheckout');
      }).catch(function (err) {
        console.error('[VM Stripe] Error al inicializar checkout:', err);
        showStripeError('Error al cargar el formulario de pago.');
      });
    }

    function showStripeError(msg) {
      if (stripeLoadingEl) stripeLoadingEl.style.display = 'none';
      if (stripeErrorEl) {
        stripeErrorEl.innerHTML = msg + '<br><button type="button" class="vm-speed-intake__back" onclick="location.reload()" style="margin-top:1rem;">Reintentar</button>';
        stripeErrorEl.classList.add('is-visible');
      }
    }

    // === PAYPAL ===
    var paypalWrap = document.getElementById('vmPayPalWrap');
    var paypalLoadingEl = document.getElementById('vmPayPalLoading');
    var paypalErrorEl = document.getElementById('vmPayPalError');
    var paypalButtonsEl = document.getElementById('vmPayPalButtons');
    var paypalRendered = false;
    var stripeWrap = document.getElementById('vmStripeWrap');
    var payMethodStripeBtn = document.getElementById('vmPayMethodStripe');
    var payMethodPayPalBtn = document.getElementById('vmPayMethodPayPal');

    // Payment method switcher
    function switchPaymentMethod(method) {
      if (method === 'stripe') {
        if (stripeWrap) stripeWrap.style.display = '';
        if (paypalWrap) paypalWrap.classList.remove('is-active');
        if (payMethodStripeBtn) payMethodStripeBtn.classList.add('is-active');
        if (payMethodPayPalBtn) payMethodPayPalBtn.classList.remove('is-active');
        if (activeBookingFlow === 'package' && paymentStage && paymentStage.classList.contains('is-active')) {
          createPackageStripeSession();
        }
      } else {
        if (stripeWrap) stripeWrap.style.display = 'none';
        if (paypalWrap) paypalWrap.classList.add('is-active');
        if (payMethodStripeBtn) payMethodStripeBtn.classList.remove('is-active');
        if (payMethodPayPalBtn) payMethodPayPalBtn.classList.add('is-active');
        if (!paypalRendered) {
          renderPayPalButtons();
        }
      }
    }

    if (payMethodStripeBtn) {
      payMethodStripeBtn.addEventListener('click', function() { switchPaymentMethod('stripe'); });
    }
    if (payMethodPayPalBtn) {
      payMethodPayPalBtn.addEventListener('click', function() { switchPaymentMethod('paypal'); });
    }

    function destroyStripeCheckout() {
      if (stripeCheckoutInstance) {
        stripeCheckoutInstance.destroy();
        stripeCheckoutInstance = null;
      }
      if (stripeCheckoutEl) {
        stripeCheckoutEl.innerHTML = '';
      }
    }

    function syncPackagePaymentControls() {
      if (activeBookingFlow !== 'package' && activeBookingFlow !== 'appointment') {
        return;
      }

      var pricing = getActivePricing();
      var isFree = pricing && Number(pricing.total) <= 0;

      if (freePackageCheckoutBtn) {
        freePackageCheckoutBtn.hidden = !isFree;
        freePackageCheckoutBtn.textContent = activeBookingFlow === 'package'
          ? 'Completar compra sin pago'
          : 'Confirmar consulta sin pago';
      }
      if (payMethodStripeBtn && payMethodPayPalBtn) {
        var methodsWrap = payMethodStripeBtn.parentElement;
        if (methodsWrap) methodsWrap.style.display = isFree ? 'none' : '';
      }

      if (isFree) {
        destroyStripeCheckout();
        if (stripeWrap) stripeWrap.style.display = 'none';
        if (paypalWrap) paypalWrap.classList.remove('is-active');
      } else if (payMethodStripeBtn && payMethodStripeBtn.classList.contains('is-active')) {
        if (stripeWrap) stripeWrap.style.display = '';
      }
    }

    function createPackageStripeSession() {
      if (activeBookingFlow !== 'package' || !selectedPackage || !pendingPackageCustomer) {
        return Promise.resolve(null);
      }

      var pricing = getPackagePricing();
      syncPackagePaymentControls();

      if (pricing && Number(pricing.total) <= 0) {
        packageStripeRequestId++;
        destroyStripeCheckout();
        return Promise.resolve(null);
      }

      var requestId = ++packageStripeRequestId;
      destroyStripeCheckout();
      if (stripeLoadingEl) stripeLoadingEl.style.display = 'flex';
      if (stripeErrorEl) stripeErrorEl.classList.remove('is-visible');

      var packageCreateUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + vmhbAction('stripeCreatePackageSession', 'vmhb_stripe_create_package_session');

      return fetch(packageCreateUrl, {
        method: 'POST',
        body: JSON.stringify({
          packageId: selectedPackage.id,
          customerData: pendingPackageCustomer,
          couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : '',
          pageUrl: window.location.href
        }),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (requestId !== packageStripeRequestId) {
            return null;
          }

          if (res.success && res.data && res.data.clientSecret) {
            currentSessionId = res.data.sessionId;
            initStripeCheckout(res.data.clientSecret);
            return res.data;
          }

          var errMsg = (res.data && res.data.message) ? res.data.message : 'Error al crear la sesión de pago.';
          showStripeError(errMsg);
          throw new Error(errMsg);
        })
        .catch(function (err) {
          if (requestId === packageStripeRequestId) {
            console.error('[VM Stripe Package] Fetch error:', err);
            showStripeError(err.message || 'Ocurrió un error de conexión. Intenta de nuevo.');
          }
          return null;
        });
    }

    function createAppointmentStripeSession(shouldMount) {
      if (activeBookingFlow !== 'appointment' || !selectedService || !pendingBookingPayload) {
        return Promise.resolve(null);
      }

      var pricing = getAppointmentPricing();
      syncPackagePaymentControls();

      if (pricing && Number(pricing.total) <= 0) {
        packageStripeRequestId++;
        destroyStripeCheckout();
        return Promise.resolve(null);
      }

      var requestId = ++packageStripeRequestId;
      destroyStripeCheckout();
      if (stripeLoadingEl) stripeLoadingEl.style.display = 'flex';
      if (stripeErrorEl) stripeErrorEl.classList.remove('is-visible');

      var createUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + vmhbAction('stripeCreateSession', 'vm_stripe_create_session');
      var serviceName = selectedService.category + ' - ' + selectedService.type;

      return fetch(createUrl, {
        method: 'POST',
        body: JSON.stringify({
          serviceName: serviceName,
          serviceId: selectedService.id,
          servicePrice: selectedService.price,
          customerEmail: getAppointmentCustomerEmail(),
          bookingData: pendingBookingPayload || {},
          doctorMode: doctorMode,
          couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : '',
          pageUrl: window.location.href
        }),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (requestId !== packageStripeRequestId) {
            return null;
          }

          if (res.success && res.data && res.data.clientSecret) {
            currentSessionId = res.data.sessionId;
            if (shouldMount !== false) {
              initStripeCheckout(res.data.clientSecret);
            }
            return res.data;
          }

          var errMsg = (res.data && res.data.message) ? res.data.message : 'Error al crear la sesión de pago.';
          showStripeError(errMsg);
          throw new Error(errMsg);
        })
        .catch(function (err) {
          if (requestId === packageStripeRequestId) {
            console.error('[VM Stripe Appointment] Fetch error:', err);
            showStripeError(err.message || 'Ocurrió un error de conexión. Intenta de nuevo.');
          }
          return null;
        });
    }

    function getAppointmentCustomerEmail() {
      if (pendingBookingPayload && pendingBookingPayload.bookings && pendingBookingPayload.bookings[0] && pendingBookingPayload.bookings[0].customer) {
        return pendingBookingPayload.bookings[0].customer.email || '';
      }
      return intakeEmail ? intakeEmail.value.trim() : '';
    }

    function setPackageCouponMessage(message, type) {
      if (!packageCouponMessage) return;
      packageCouponMessage.textContent = message || '';
      packageCouponMessage.classList.toggle('is-success', type === 'success');
      packageCouponMessage.classList.toggle('is-error', type === 'error');
    }

    function applyPackageCoupon() {
      if (!packageCouponInput) {
        return;
      }

      if (activeBookingFlow === 'package' && (!selectedPackage || !pendingPackageCustomer)) {
        return;
      }

      if (activeBookingFlow === 'appointment' && (!selectedService || !pendingBookingPayload)) {
        return;
      }

      var code = packageCouponInput.value.trim();

      if (!code) {
        pendingPackageCoupon = null;
        pendingPackagePricing = null;
        setPackageCouponMessage('Cupón eliminado.', 'success');
        updatePaymentSummary();
        if (payMethodStripeBtn && payMethodStripeBtn.classList.contains('is-active')) {
          activeBookingFlow === 'package' ? createPackageStripeSession() : createAppointmentStripeSession();
        }
        return;
      }

      if (packageCouponApply) {
        packageCouponApply.disabled = true;
        packageCouponApply.textContent = 'Validando...';
      }
      setPackageCouponMessage('Validando cupón...', '');

      var validateAction = activeBookingFlow === 'package'
        ? vmhbAction('validatePackageCoupon', 'vmhb_amelia_validate_package_coupon')
        : vmhbAction('validateAppointmentCoupon', 'vmhb_amelia_validate_appointment_coupon');
      var validateUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + validateAction;
      var validatePayload = activeBookingFlow === 'package'
        ? {
            packageId: selectedPackage.id,
            couponCode: code,
            customerEmail: pendingPackageCustomer.email
          }
        : {
            serviceId: selectedService.id,
            servicePrice: selectedService.price,
            couponCode: code,
            customerEmail: getAppointmentCustomerEmail()
          };

      fetch(validateUrl, {
        method: 'POST',
        body: JSON.stringify(validatePayload),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.coupon && res.data.pricing) {
            pendingPackageCoupon = res.data.coupon;
            pendingPackagePricing = res.data.pricing;
            setPackageCouponMessage('Cupón aplicado: ' + pendingPackageCoupon.code, 'success');
            updatePaymentSummary();
            if (payMethodStripeBtn && payMethodStripeBtn.classList.contains('is-active')) {
              activeBookingFlow === 'package' ? createPackageStripeSession() : createAppointmentStripeSession();
            }
            return;
          }

          pendingPackageCoupon = null;
          pendingPackagePricing = null;
          updatePaymentSummary();
          setPackageCouponMessage((res.data && res.data.message) ? res.data.message : 'Cupón inválido.', 'error');
          if (payMethodStripeBtn && payMethodStripeBtn.classList.contains('is-active')) {
            activeBookingFlow === 'package' ? createPackageStripeSession() : createAppointmentStripeSession();
          }
        })
        .catch(function () {
          setPackageCouponMessage('No pudimos validar el cupón. Intenta de nuevo.', 'error');
        })
        .finally(function () {
          if (packageCouponApply) {
            packageCouponApply.disabled = false;
            packageCouponApply.textContent = 'Aplicar';
          }
        });
    }

    function completeFreePackagePurchase() {
      if (activeBookingFlow === 'package' && (!selectedPackage || !pendingPackageCustomer)) {
        return;
      }

      if (activeBookingFlow === 'appointment' && (!selectedService || !pendingBookingPayload)) {
        return;
      }

      var pricing = getActivePricing();
      if (!pricing || Number(pricing.total) > 0) {
        setPackageCouponMessage(activeBookingFlow === 'package' ? 'Este paquete todavía requiere pago.' : 'Esta consulta todavía requiere pago.', 'error');
        return;
      }

      if (freePackageCheckoutBtn) {
        freePackageCheckoutBtn.disabled = true;
        freePackageCheckoutBtn.textContent = activeBookingFlow === 'package' ? 'Registrando paquete...' : 'Confirmando consulta...';
      }

      var freeAction = activeBookingFlow === 'package'
        ? vmhbAction('completeFreePackagePurchase', 'vmhb_complete_free_package_purchase')
        : vmhbAction('completeFreeAppointmentBooking', 'vmhb_complete_free_appointment_booking');
      var freeUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + freeAction;
      if (!pendingPackageAttemptId) {
        pendingPackageAttemptId = makePackageAttemptId();
      }
      var freePayload = activeBookingFlow === 'package'
        ? {
            packageId: selectedPackage.id,
            customerData: pendingPackageCustomer,
            couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : '',
            attemptId: pendingPackageAttemptId
          }
        : {
            serviceId: selectedService.id,
            servicePrice: selectedService.price,
            customerEmail: getAppointmentCustomerEmail(),
            bookingData: pendingBookingPayload || {},
            doctorMode: doctorMode,
            couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : '',
            attemptId: pendingPackageAttemptId
          };

      fetch(freeUrl, {
        method: 'POST',
        body: JSON.stringify(freePayload),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.status === 'complete') {
            showSuccessFromPayment();
            return;
          }

          setPackageCouponMessage((res.data && res.data.message) ? res.data.message : 'No se pudo completar la operación.', 'error');
        })
        .catch(function () {
          setPackageCouponMessage('No se pudo completar la operación. Intenta de nuevo.', 'error');
        })
        .finally(function () {
          if (freePackageCheckoutBtn) {
            freePackageCheckoutBtn.disabled = false;
            freePackageCheckoutBtn.textContent = activeBookingFlow === 'package' ? 'Completar compra sin pago' : 'Confirmar consulta sin pago';
          }
        });
    }

    if (packageCouponApply) {
      packageCouponApply.addEventListener('click', applyPackageCoupon);
    }

    if (packageCouponInput) {
      packageCouponInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyPackageCoupon();
        }
      });
    }

    if (freePackageCheckoutBtn) {
      freePackageCheckoutBtn.addEventListener('click', completeFreePackagePurchase);
    }

    function renderPayPalButtons() {
      if (typeof paypal === 'undefined') {
        if (paypalErrorEl) {
          paypalErrorEl.textContent = 'PayPal SDK no se pudo cargar. Recarga la página.';
          paypalErrorEl.classList.add('is-visible');
        }
        return;
      }

      paypalRendered = true;
      if (paypalLoadingEl) paypalLoadingEl.classList.add('is-visible');

      paypal.Buttons({
        style: {
          layout: 'vertical',
          color: 'gold',
          shape: 'pill',
          label: 'paypal',
          height: 48
        },
        createOrder: function() {
          if (activeBookingFlow === 'package') {
            if (!selectedPackage || !pendingPackageCustomer) {
              throw new Error('Faltan datos del paquete para crear la orden PayPal.');
            }

            var packageCreateUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + vmhbAction('paypalCreatePackageOrder', 'vmhb_paypal_create_package_order');

            return fetch(packageCreateUrl, {
              method: 'POST',
              body: JSON.stringify({
                packageId: selectedPackage.id,
                customerData: pendingPackageCustomer,
                couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : ''
              }),
              headers: { 'Content-Type': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
              if (res.success && res.data && res.data.orderID) {
                return res.data.orderID;
              }
              var msg = (res.data && res.data.message) ? res.data.message : 'Error al crear orden PayPal';
              throw new Error(msg);
            });
          }

          var serviceName = selectedService
            ? selectedService.category + ' - ' + selectedService.type
            : 'Consulta VirtualMD';
          var servicePrice = selectedService ? selectedService.price : 0;

          var createUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + vmhbAction('paypalCreateOrder', 'vm_paypal_create_order');

          return fetch(createUrl, {
            method: 'POST',
            body: JSON.stringify({
              serviceName: serviceName,
              servicePrice: servicePrice,
              bookingData: pendingBookingPayload || {},
              doctorMode: doctorMode,
              couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : ''
            }),
            headers: { 'Content-Type': 'application/json' }
          })
          .then(function(res) { return res.json(); })
          .then(function(res) {
            if (res.success && res.data && res.data.orderID) {
              return res.data.orderID;
            }
            var msg = (res.data && res.data.message) ? res.data.message : 'Error al crear orden PayPal';
            throw new Error(msg);
          });
        },
        onApprove: function(data) {
          if (paypalLoadingEl) {
            paypalLoadingEl.classList.add('is-visible');
            paypalLoadingEl.querySelector('.vm-speed-spinner') && (paypalLoadingEl.querySelector('.vm-speed-spinner').style.display = '');
          }
          if (paypalButtonsEl) paypalButtonsEl.style.display = 'none';

          var captureUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + (
            activeBookingFlow === 'package'
              ? vmhbAction('paypalCapturePackageOrder', 'vmhb_paypal_capture_package_order')
              : vmhbAction('paypalCaptureOrder', 'vm_paypal_capture_order')
          );
          var capturePayload = activeBookingFlow === 'package'
            ? {
                orderID: data.orderID,
                packageId: selectedPackage ? selectedPackage.id : 0,
                customerData: pendingPackageCustomer || {},
                couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : ''
              }
            : {
                orderID: data.orderID,
                bookingData: pendingBookingPayload || {},
                doctorMode: doctorMode,
                couponCode: pendingPackageCoupon ? pendingPackageCoupon.code : ''
              };

          return fetch(captureUrl, {
            method: 'POST',
            body: JSON.stringify(capturePayload),
            headers: { 'Content-Type': 'application/json' }
          })
          .then(function(res) { return res.json(); })
          .then(function(res) {
            if (paypalLoadingEl) paypalLoadingEl.classList.remove('is-visible');

            if (res.success && res.data && res.data.status === 'COMPLETED') {
              // Pago exitoso; la consulta se registra en Amelia en segundo plano.
              showSuccessFromPayment();
            } else {
              var msg = (res.data && res.data.message) ? res.data.message : 'El pago no se completó';
              var paymentCompleted = !!(res.data && res.data.payment_completed);
              if (paymentCompleted) {
                var resultKey = activeBookingFlow === 'package' ? 'package_result' : 'booking_result';
                var technicalDetail = (res.data[resultKey] && res.data[resultKey].message)
                  ? res.data[resultKey].message
                  : msg;
                msg = activeBookingFlow === 'package'
                  ? 'Tu pago fue aprobado, pero no pudimos registrar el paquete automáticamente. Detalle: ' + technicalDetail + '. Escríbenos para completar la compra con tu comprobante PayPal.'
                  : 'Tu pago fue aprobado, pero no pudimos registrar la consulta automáticamente. Detalle: ' + technicalDetail + '. Escríbenos para completar el agendamiento con tu comprobante PayPal.';
                console.error('[VM PayPal] Amelia registration failed after completed payment:', JSON.stringify(res.data));
              }
              if (paypalErrorEl) {
                paypalErrorEl.textContent = msg;
                paypalErrorEl.classList.add('is-visible');
              }
              if (!paymentCompleted && paypalButtonsEl) paypalButtonsEl.style.display = '';
            }
          })
          .catch(function(err) {
            if (paypalLoadingEl) paypalLoadingEl.classList.remove('is-visible');
            if (paypalErrorEl) {
              paypalErrorEl.textContent = 'Error de conexión: ' + err.message;
              paypalErrorEl.classList.add('is-visible');
            }
            if (paypalButtonsEl) paypalButtonsEl.style.display = '';
          });
        },
        onError: function(err) {
          console.error('[VM PayPal] Error:', err);
          if (paypalErrorEl) {
            paypalErrorEl.textContent = 'Ocurrió un error con PayPal. Intenta de nuevo.';
            paypalErrorEl.classList.add('is-visible');
          }
        },
        onCancel: function() {
          // El usuario canceló el flujo de PayPal, no hacemos nada
        }
      }).render('#vmPayPalButtons').then(function() {
        if (paypalLoadingEl) paypalLoadingEl.classList.remove('is-visible');
      });
    }

    function verifyStripePayment(sessionId) {
      var actionName = activeBookingFlow === 'package'
        ? vmhbAction('stripeVerifyPackagePayment', 'vmhb_stripe_verify_package_payment')
        : vmhbAction('stripeVerifyPayment', 'vmhb_stripe_verify_payment');
      var verifyUrl = VM_AJAX_URL + (VM_AJAX_URL.indexOf('?') !== -1 ? '&' : '?') + 'action=' + actionName;

      return fetch(verifyUrl, {
        method: 'POST',
        body: JSON.stringify({ session_id: sessionId }),
        headers: { 'Content-Type': 'application/json' }
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.status === 'complete') {
            return { success: true, data: res.data };
          }
          return { success: false, data: res.data || {} };
        })
        .catch(function (err) {
          console.error('[VM Stripe] Verify error:', err);
          return { success: false, error: err.message };
        });
    }

    function updateSpecialtyValue() {
      if (!selectedService) {
        specialtyValue.textContent = 'Elige categoría, consulta y precio';
        return;
      }
      specialtyValue.textContent = selectedService.category + ' · ' + selectedService.type + ' · ' + formatPrice(selectedService.price);
    }

    function updateDateValue() {
      if (!selectedDate || !selectedTime) {
        dateValue.textContent = 'Selecciona día y horario';
        return;
      }
      dateValue.textContent = shortDateFormatter.format(selectedDate).replace('.', '') + ' · ' + formatTimeRange(selectedTime);
    }

    function updateDoctorValue() {
      if (!doctorValue) {
        return;
      }
      if (doctorMode !== 'manual') {
        doctorValue.textContent = selectedDoctor && selectedDoctor.name ? selectedDoctor.name : 'Asignación automática';
        return;
      }
      if (!selectedDoctor) {
        doctorValue.textContent = 'Selecciona un doctor';
        return;
      }
      doctorValue.textContent = selectedDoctor.name;
    }

    function setDoctorMode(mode) {
      doctorMode = mode === 'manual' ? 'manual' : 'auto';

      if (doctorModeButtons.length) {
        doctorModeButtons.forEach(function (button) {
          var isActive = button.dataset.doctorMode === doctorMode;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
      }

      bookingWidget.classList.toggle('is-manual', doctorMode === 'manual');

      if (doctorMode !== 'manual') {
        selectedDoctor = null;
        closeField(doctorField, doctorPopover, doctorToggle);
        // Recargar slots sin filtro de doctor
        if (selectedService) {
          loadSlotsForMonth();
        }
      } else {
        // Cargar doctores desde API filtrados por servicio
        if (selectedService) {
          loadDoctors(selectedService.id);
        } else {
          renderDoctorList();
        }
      }

      updateDoctorValue();
      updateIntakeSummary();
    }

    function openField(field, popover, toggle) {
      if (!field || !popover || !toggle) return;
      closeAllFields(field);
      popover.hidden = false;
      requestAnimationFrame(function () {
        field.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
      });
    }

    function closeField(field, popover, toggle) {
      if (!field || !popover || !toggle) return;
      field.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      setTimeout(function () {
        if (!field.classList.contains('is-open')) {
          popover.hidden = true;
        }
      }, 220);
    }

    function closeAllFields(exceptField) {
      if (exceptField !== specialtyField) {
        closeField(specialtyField, specialtyPopover, specialtyToggle);
        showMobileCategories();
      }
      if (exceptField !== doctorField) {
        closeField(doctorField, doctorPopover, doctorToggle);
      }
      if (exceptField !== dateField) {
        closeField(dateField, datePopover, dateToggle);
      }
    }

    function renderCategories() {
      categoryList.innerHTML = '';
      var q = specialtySearch ? specialtySearch.value.trim().toLowerCase() : '';

      var visibleCategories = [];
      specialtyData.forEach(function (category, index) {
        if (!q) {
          visibleCategories.push(index);
          return;
        }
        var catMatch = category.category.toLowerCase().indexOf(q) > -1;
        var svcMatch = category.services.some(function (s) { return s.type.toLowerCase().indexOf(q) > -1; });
        if (catMatch || svcMatch) {
          visibleCategories.push(index);
        }
      });

      if (visibleCategories.length > 0 && visibleCategories.indexOf(selectedCategoryIndex) === -1) {
        selectedCategoryIndex = visibleCategories[0];
      }

      specialtyData.forEach(function (category, index) {
        if (visibleCategories.indexOf(index) === -1) return;

        var matchingCount = category.services.length;
        if (q) {
          var matchingServices = category.services.filter(function (s) {
            return s.type.toLowerCase().indexOf(q) > -1 || category.category.toLowerCase().indexOf(q) > -1;
          });
          matchingCount = matchingServices.length;
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vm-speed-category-item' + (index === selectedCategoryIndex ? ' is-active' : '');
        btn.innerHTML = '<span>' + category.category + '</span><span class="vm-speed-category-count">(' + matchingCount + ')</span>';
        btn.addEventListener('click', function () {
          selectedCategoryIndex = index;
          renderCategories();
          renderServices();
          if (isMobileView()) {
            showMobileServices();
          }
        });
        categoryList.appendChild(btn);
      });

      if (visibleCategories.length === 0) {
        categoryList.innerHTML = '<div style="padding:1rem;color:#7a86a2;font-size:0.9rem;">Sin resultados</div>';
      }
    }

    function renderServices() {
      var currentCategory = specialtyData[selectedCategoryIndex];
      serviceList.innerHTML = '';
      if (!currentCategory || !currentCategory.services) return;

      // Mobile: add back button
      if (isMobileView()) {
        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'vm-speed-service-back';
        backBtn.innerHTML = '← ' + currentCategory.category;
        backBtn.addEventListener('click', function () {
          showMobileCategories();
        });
        serviceList.appendChild(backBtn);
      }

      var q = specialtySearch ? specialtySearch.value.trim().toLowerCase() : '';
      var hasResults = false;

      currentCategory.services.forEach(function (service) {
        if (q) {
          var catMatch = currentCategory.category.toLowerCase().indexOf(q) > -1;
          var svcMatch = service.type.toLowerCase().indexOf(q) > -1;
          if (!catMatch && !svcMatch) return; // Salta este servicio si no coincide
        }

        hasResults = true;
        var full = {
          id: service.id,
          categoryId: currentCategory.categoryId,
          category: currentCategory.category,
          type: service.type,
          mode: service.mode,
          price: service.price,
          duration: service.duration || 3600
        };
        var active = selectedService &&
          selectedService.category === full.category &&
          selectedService.type === full.type &&
          selectedService.id === full.id;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vm-speed-service-item' + (active ? ' is-active' : '');
        btn.innerHTML =
          '<span class="vm-speed-service-title">' + full.type + '</span>' +
          '<span class="vm-speed-service-meta"><span>' + full.mode + '</span><span class="vm-speed-service-price">' + formatPrice(full.price) + '</span></span>';
        btn.addEventListener('click', function () {
          var serviceChanged = !selectedService || selectedService.id !== full.id;
          selectedService = full;
          updateSpecialtyValue();
          renderServices();
          // Al cambiar servicio, recargar doctores y slots
          if (serviceChanged) {
            selectedDate = null;
            selectedTime = null;
            slotsData = {};
            occupiedData = {};
            providerMapData = {};
            slotsPayloadLoaded = false;
            updateDateValue();
            if (doctorMode === 'manual') {
              loadDoctors(full.id);
              // Auto-abrir el dropdown de doctor
              if (doctorField && doctorPopover && doctorToggle) {
                openField(doctorField, doctorPopover, doctorToggle);
              }
            } else {
              loadDoctors(full.id);
              loadSlotsForMonth();
              // Auto-abrir el popover de fecha (muestra skeleton mientras carga)
              openField(dateField, datePopover, dateToggle);
            }
          }
          closeField(specialtyField, specialtyPopover, specialtyToggle);
        });
        serviceList.appendChild(btn);
      });

      if (q && !hasResults) {
        serviceList.innerHTML = '<div style="padding:1rem;text-align:center;color:#7a86a2;font-size:0.95rem;">No se encontraron servicios</div>';
      }
    }

    function renderDoctorList() {
      if (!doctorList) {
        return;
      }

      doctorList.innerHTML = '';

      if (isLoadingDoctors) {
        doctorList.innerHTML = '<div style="padding:1rem;text-align:center;color:#7a86a2;font-size:0.95rem;">Cargando doctores…</div>';
        return;
      }

      if (!doctorData.length) {
        doctorList.innerHTML = '<div style="padding:1rem;text-align:center;color:#7a86a2;font-size:0.95rem;">No hay doctores disponibles para esta especialidad</div>';
        return;
      }

      var q = doctorSearch ? doctorSearch.value.trim().toLowerCase() : '';
      var hasResults = false;

      doctorData.forEach(function (doctor) {
        if (q && doctor.name.toLowerCase().indexOf(q) === -1) {
          return; // Salta si no coincide
        }
        hasResults = true;
        var active = selectedDoctor && selectedDoctor.id === doctor.id;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vm-speed-doctor-item' + (active ? ' is-active' : '');
        btn.innerHTML =
          doctorAvatarHTML(doctor, 'vm-speed-doctor-avatar') +
          '<span class="vm-speed-doctor-name">' + escapeHTML(getDoctorName(doctor)) + '</span>';
        btn.addEventListener('click', function () {
          var doctorChanged = !selectedDoctor || selectedDoctor.id !== doctor.id;
          selectedDoctor = doctor;
          updateDoctorValue();
          renderDoctorList();
          closeField(doctorField, doctorPopover, doctorToggle);
          // Recargar slots con el doctor seleccionado
          if (doctorChanged && selectedService) {
            selectedDate = null;
            selectedTime = null;
            slotsData = {};
            occupiedData = {};
            providerMapData = {};
            slotsPayloadLoaded = false;
            updateDateValue();
            loadSlotsForMonth();
            // Auto-abrir el popover de fecha (muestra skeleton mientras carga)
            openField(dateField, datePopover, dateToggle);
          }
        });
        doctorList.appendChild(btn);
      });

      if (q && !hasResults) {
        doctorList.innerHTML = '<div style="padding:1rem;text-align:center;color:#7a86a2;font-size:0.95rem;">No se encontraron doctores</div>';
      }
    }

    function renderWeekdays() {
      weekdays.innerHTML = '';
      weekLabels.forEach(function (label) {
        var div = document.createElement('div');
        div.className = 'vm-speed-weekday';
        div.textContent = label;
        weekdays.appendChild(div);
      });
    }

    function formatDateKey(d) {
      var y = d.getFullYear();
      var m = String(d.getMonth() + 1).padStart(2, '0');
      var day = String(d.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + day;
    }

    function renderCalendar() {
      clampCalendarMonth();
      calTitle.textContent = monthFormatter.format(calendarMonth);
      calDays.innerHTML = '';

      if (calPrev) {
        calPrev.disabled = isBeforeMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1), minCalendarMonth);
      }
      if (calNext) {
        calNext.disabled = isAfterMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1), maxCalendarMonth);
      }

      // Mostrar skeleton mientras cargan los slots
      if (isLoadingSlots) {
        var html = '';
        for (var sk = 0; sk < 35; sk++) {
          html += '<div class="vm-speed-cal-skeleton"></div>';
        }
        html += '<div style="grid-column:1/-1;text-align:center;color:#7a86a2;padding:0.5rem 0;font-size:0.85rem;">' +
          '<span class="vm-speed-spinner"></span> Cargando disponibilidad…</div>';
        calDays.innerHTML = html;
        return;
      }

      var start = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
      var end = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 0);
      var startDay = (start.getDay() + 6) % 7;

      for (var i = 0; i < startDay; i++) {
        var empty = document.createElement('div');
        calDays.appendChild(empty);
      }

      for (var day = 1; day <= end.getDate(); day++) {
        var current = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), day);
        var dateKey = formatDateKey(current);
        var hasSlots = slotsData[dateKey] && slotsData[dateKey].length > 0;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vm-speed-day';
        btn.textContent = String(day);
        if (isSameDate(current, selectedDate)) {
          btn.classList.add('is-active');
        }

        var isPast = current < todayStart;
        var isAfterWindow = current > maxAvailabilityDate;
        var hasLoadedSlots = selectedService && slotsPayloadLoaded;
        var hasSlots = slotsData[dateKey] && slotsData[dateKey].length > 0;
        var noAvail = hasLoadedSlots && !hasSlots;

        if (isPast || isAfterWindow) {
          btn.disabled = true;
        } else if (noAvail) {
          // Si ya cargaron los datos y este día no tiene slots disponibles (ya sea porque no atiende o porque está lleno)
          btn.disabled = true;
          btn.classList.add('is-unavailable');
        } else if (hasLoadedSlots && hasSlots) {
          btn.classList.add('is-available');
        }

        if (!isPast && !isAfterWindow && !btn.disabled) {
          btn.addEventListener('click', function (selectedDay) {
            return function () {
              selectedDate = selectedDay;
              selectedTime = null;
              if (doctorMode !== 'manual') {
                selectedDoctor = null;
                updateDoctorValue();
              }
              renderCalendar();
              renderTimes();
              updateDateValue();
            };
          }(current));
        }
        calDays.appendChild(btn);
      }
    }

    function renderTimes() {
      timeList.innerHTML = '';

      if (isLoadingSlots) {
        timeList.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#7a86a2;padding:0.8rem;font-size:0.9rem;"><span class="vm-speed-spinner"></span> Cargando horarios\u2026</div>';
        return;
      }

      if (!selectedDate) {
        timeList.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#7a86a2;padding:0.8rem;font-size:0.9rem;">Selecciona un día primero</div>';
        return;
      }

      var dateKey = formatDateKey(selectedDate);
      var availableTimes = slotsData[dateKey] || [];

      if (!availableTimes.length) {
        timeList.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#7a86a2;padding:0.8rem;font-size:0.9rem;">No hay horarios disponibles este día</div>';
        return;
      }

      availableTimes.forEach(function (time) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vm-speed-time-item is-available' + (selectedTime === time ? ' is-active' : '');
        btn.textContent = formatTimeRange(time);
        btn.addEventListener('click', function () {
          selectedTime = time;
          syncAutoDoctorForSelectedTime();
          renderTimes();
          updateDateValue();

          if (selectedDate) {
            closeField(dateField, datePopover, dateToggle);
          }
        });
        timeList.appendChild(btn);
      });
    }

    specialtyToggle.addEventListener('click', function () {
      var isOpen = specialtyField.classList.contains('is-open');
      if (isOpen) {
        closeField(specialtyField, specialtyPopover, specialtyToggle);
      } else {
        openField(specialtyField, specialtyPopover, specialtyToggle);
        if (specialtySearch) {
          setTimeout(function () { specialtySearch.focus(); }, 100);
        }
      }
    });

    if (specialtySearch) {
      specialtySearch.addEventListener('input', function () {
        renderCategories();
        renderServices();
      });
    }

    if (doctorSearch) {
      doctorSearch.addEventListener('input', function () {
        renderDoctorList();
      });
    }

    if (doctorToggle && doctorField && doctorPopover) {
      doctorToggle.addEventListener('click', function () {
        if (doctorMode !== 'manual') {
          return;
        }
        if (!selectedService) {
          alert('Por favor, selecciona una especialidad y servicio primero.');
          openField(specialtyField, specialtyPopover, specialtyToggle);
          return;
        }
        var isOpen = doctorField.classList.contains('is-open');
        if (isOpen) {
          closeField(doctorField, doctorPopover, doctorToggle);
        } else {
          openField(doctorField, doctorPopover, doctorToggle);
          if (doctorSearch) {
            setTimeout(function () { doctorSearch.focus(); }, 100);
          }
        }
      });
    }

    if (doctorModeButtons.length) {
      doctorModeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          setDoctorMode(button.dataset.doctorMode);
        });
      });
    }

    dateToggle.addEventListener('click', function () {
      if (!selectedService) {
        alert('Por favor, selecciona una especialidad primero.');
        openField(specialtyField, specialtyPopover, specialtyToggle);
        return;
      }
      if (doctorMode === 'manual' && !selectedDoctor) {
        alert('Por favor, selecciona un doctor primero.');
        openField(doctorField, doctorPopover, doctorToggle);
        return;
      }

      var isOpen = dateField.classList.contains('is-open');
      if (isOpen) {
        closeField(dateField, datePopover, dateToggle);
      } else {
        openField(dateField, datePopover, dateToggle);
      }
    });

    calPrev.addEventListener('click', function () {
      var prevMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
      if (isBeforeMonth(prevMonth, minCalendarMonth)) {
        return;
      }
      calendarMonth = prevMonth;
      loadSlotsForMonth();
    });

    calNext.addEventListener('click', function () {
      var nextMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
      if (isAfterMonth(nextMonth, maxCalendarMonth)) {
        return;
      }
      calendarMonth = nextMonth;
      loadSlotsForMonth();
    });

    bookingCta.addEventListener('click', function (event) {
      event.preventDefault();
      if (!hasAllSelections()) {
        if (!selectedService) {
          openField(specialtyField, specialtyPopover, specialtyToggle);
          return;
        }
        if (doctorMode === 'manual' && !selectedDoctor) {
          openField(doctorField, doctorPopover, doctorToggle);
          return;
        }
        openField(dateField, datePopover, dateToggle);
        return;
      }
      showIntakeStep();
    });

    heroTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        setBookingFlow(tab.dataset.tab === 'paquetes' ? 'package' : 'appointment');
      });
    });

    if (intakeSummary) {
      intakeSummary.addEventListener('click', function (event) {
        var button = event.target.closest('.vm-speed-summary-package-content');
        if (!button) {
          return;
        }
        event.preventDefault();
        openPackageContentModal(selectedPackage);
      });
    }

    if (backToBookingBtn) {
      backToBookingBtn.addEventListener('click', function () {
        showBookingStep();
      });
    }

    // --- Botón "Volver" desde Payment stage ---
    if (backToFormBtn) {
      backToFormBtn.addEventListener('click', function () {
        showFormFromPayment();
      });
    }

    if (submitBookingBtn) {
      submitBookingBtn.addEventListener('click', function (event) {
        event.preventDefault();
        var n = intakeName.value.trim();
        var p = intakePhone.value.trim();
        var e = intakeEmail.value.trim();
        var phoneCountry = getSelectedPhoneCountry();
        var formattedPhone = normalizePhoneForBooking(p, phoneCountry);
        if (!n || !p || !e) {
          alert('Por favor, completa nombre, teléfono y correo electrónico.');
          return;
        }

        if (!formattedPhone) {
          alert('Por favor, escribe un teléfono válido.');
          return;
        }

        if (!isPhoneValidForCountry(p, phoneCountry, formattedPhone)) {
          alert('Por favor, revisa el número de teléfono para la lada seleccionada.');
          return;
        }

        if (activeBookingFlow === 'package') {
          if (!selectedPackage) {
            alert('Selecciona un paquete para continuar.');
            showBookingStep();
            return;
          }

          submitBookingBtn.disabled = true;
          submitBookingBtn.textContent = 'Procesando...';

          var packageCity = intakeCity ? intakeCity.value.trim() : '';
          var packageNote = intakeMessage ? intakeMessage.value.trim() : '';
          var packageNameParts = n.split(' ');
          var packageFirstName = packageNameParts.shift();
          var packageLastName = packageNameParts.length > 0 ? packageNameParts.join(' ') : ' ';

          pendingBookingPayload = null;
          resetPackageCouponState();
          pendingPackageAttemptId = makePackageAttemptId();
          pendingPackageCustomer = {
            name: n,
            firstName: packageFirstName,
            lastName: packageLastName,
            email: e,
            phone: formattedPhone,
            countryPhoneIso: phoneCountry.iso,
            city: packageCity,
            message: packageNote
          };

          submitBookingBtn.disabled = false;
          submitBookingBtn.textContent = 'Continuar al pago';
          updatePaymentSummary();
          switchPaymentMethod('stripe');
          showPaymentStep();
          setTimeout(function () {
            createPackageStripeSession();
          }, 500);
          return;
        }

        if (!selectedDate || !selectedTime || !selectedService) {
          alert('Faltan datos de la consulta. Regresa y completa la selección.');
          return;
        }

        if (doctorMode !== 'manual' && getCurrentAutoProviderIds().length > 1 && !selectedDoctor) {
          renderAutoDoctorChoice();
          alert('Por favor, elige el especialista para este horario.');
          return;
        }

        submitBookingBtn.disabled = true;
        submitBookingBtn.textContent = 'Procesando...';

        // Construir el payload para Amelia (se guardará en metadata de Stripe)
        var dateStr = selectedDate.getFullYear() + '-' +
          String(selectedDate.getMonth() + 1).padStart(2, '0') + '-' +
          String(selectedDate.getDate()).padStart(2, '0');
        var startDateTime = dateStr + ' ' + selectedTime;

        var city = intakeCity ? intakeCity.value.trim() : '';
        var note = intakeMessage ? intakeMessage.value.trim() : '';
        var notesCombined = '';
        if (city) notesCombined += 'Ciudad: ' + city + '\n';
        if (note) notesCombined += 'Mensaje: ' + note;

        var nameParts = n.split(' ');
        var firstName = nameParts.shift();
        var lastName = nameParts.length > 0 ? nameParts.join(' ') : ' ';

        // Payload que se enviará a Amelia DESPUÉS del pago exitoso
        var ameliaPayload = {
          type: 'appointment',
          bookingStart: startDateTime,
          notifyParticipants: 1,
          locationId: 1,
          providerId: selectedDoctor ? selectedDoctor.id : 0,
          serviceId: selectedService ? selectedService.id : 0,
          bookings: [{
            persons: 1,
            duration: selectedService && selectedService.duration ? selectedService.duration : 0,
            customerId: null,
            customer: {
              id: null,
              firstName: firstName,
              lastName: lastName,
              email: e,
              phone: formattedPhone,
              countryPhoneIso: phoneCountry.iso,
              externalId: null
            },
            extras: [],
            customFields: {}
          }],
          payment: {
            gateway: 'onSite',
            currency: 'MXN',
            data: {}
          },
          runInstantPostBookingActions: true
        };

        if (notesCombined) {
          ameliaPayload.internalNotes = notesCombined;
        }

        pendingBookingPayload = ameliaPayload;
        resetPackageCouponState();
        pendingPackageAttemptId = makePackageAttemptId();

        createAppointmentStripeSession(false)
          .then(function (sessionData) {
            submitBookingBtn.disabled = false;
            submitBookingBtn.textContent = 'Continuar al pago';

            if (sessionData && sessionData.clientSecret) {
              // Actualizar resumen de pago
              updatePaymentSummary();

              // Transicionar al stage de pago
              showPaymentStep();
              setTimeout(function () {
                initStripeCheckout(sessionData.clientSecret);
              }, 500);
            } else {
              alert('Error al crear la sesión de pago.');
            }
          })
          .catch(function (err) {
            console.error('[VM Stripe] Fetch error:', err);
            submitBookingBtn.disabled = false;
            submitBookingBtn.textContent = 'Continuar al pago';
            alert('Ocurrió un error de conexión. Intenta de nuevo.');
          });
      });
    }

    document.addEventListener('click', function (event) {
      var insideAnyField = false;
      if (specialtyField && specialtyField.contains(event.target)) insideAnyField = true;
      if (doctorField && doctorField.contains(event.target)) insideAnyField = true;
      if (dateField && dateField.contains(event.target)) insideAnyField = true;
      if (!insideAnyField) {
        closeAllFields(null);
      }
    });

    [specialtyPopover, doctorPopover, datePopover].forEach(function (popover) {
      if (popover) {
        popover.addEventListener('click', function (event) {
          event.stopPropagation();
        });
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (heroStages && heroStages.classList.contains('is-form-active')) {
          showBookingStep();
          return;
        }
        closeAllFields(null);
      }
    });

    // ====== FUNCIONES DE CARGA DESDE API DE AMELIA ======

    function applySlotsPayload(payload) {
      slotsData = payload && payload.slots ? payload.slots : {};
      occupiedData = payload && payload.occupied ? payload.occupied : {};
      providerMapData = payload && payload.providerMap ? payload.providerMap : {};
      slotsPayloadLoaded = !!payload;
    }

    function clearAvailabilityState() {
      slotsData = {};
      occupiedData = {};
      providerMapData = {};
      slotsPayloadLoaded = false;
    }

    function loadCatalog() {
      isLoadingCatalog = true;
      specialtyValue.textContent = 'Cargando especialidades…';
      categoryList.innerHTML = '<div style="padding:1rem;text-align:center;color:#7a86a2;font-size:0.9rem;">Cargando…</div>';

      fetch(VM_AJAX_URL + '?action=' + vmhbAction('catalog', 'vm_amelia_get_catalog'))
        .then(function (res) { return res.json(); })
        .then(function (json) {
          isLoadingCatalog = false;
          if (json.success && json.data && json.data.length) {
            specialtyData = json.data;
            selectedCategoryIndex = 0;
            renderCategories();
            renderServices();
            updateSpecialtyValue();
          } else {
            categoryList.innerHTML = '<div style="padding:1rem;text-align:center;color:#c44;">No se encontraron especialidades</div>';
            console.warn('[VM Booking] Catalog response:', json);
          }
        })
        .catch(function (err) {
          isLoadingCatalog = false;
          categoryList.innerHTML = '<div style="padding:1rem;text-align:center;color:#c44;">Error al cargar especialidades</div>';
          specialtyValue.textContent = 'Error al cargar, intenta recargar';
          console.error('[VM Booking] Error loading catalog:', err);
        });
    }

    function loadDoctors(serviceId) {
      var cacheKey = String(serviceId || 0);

      isLoadingDoctors = true;
      selectedDoctor = null;
      doctorData = [];
      renderDoctorList();
      updateDoctorValue();

      if (doctorCacheByService[cacheKey]) {
        isLoadingDoctors = false;
        doctorData = doctorCacheByService[cacheKey].slice();
        hydrateSelectedDoctorFromData();
        renderDoctorList();
        renderAutoDoctorChoice();
        updateIntakeSummary();
        return;
      }

      var url = VM_AJAX_URL + '?action=' + vmhbAction('providers', 'vm_amelia_get_providers');
      if (serviceId) {
        url += '&serviceId=' + serviceId;
      }

      var requestId = ++activeDoctorsRequestId;

      fetch(url)
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (requestId !== activeDoctorsRequestId) {
            return;
          }
          isLoadingDoctors = false;
          if (json.success && json.data) {
            doctorData = json.data;
            doctorCacheByService[cacheKey] = json.data.slice();
            hydrateSelectedDoctorFromData();
          }
          renderDoctorList();
          renderAutoDoctorChoice();
          updateIntakeSummary();
        })
        .catch(function (err) {
          if (requestId !== activeDoctorsRequestId) {
            return;
          }
          isLoadingDoctors = false;
          doctorData = [];
          renderDoctorList();
          console.error('[VM Booking] Error loading doctors:', err);
        });
    }

    function loadSlotsForMonth() {
      clampCalendarMonth();

      if (!selectedService) {
        applySlotsPayload(null);
        renderCalendar();
        renderTimes();
        return;
      }

      if (doctorMode === 'manual' && !selectedDoctor) {
        console.log('[VM Booking] Skipping slot load — waiting for doctor selection');
        applySlotsPayload(null);
        renderCalendar();
        renderTimes();
        return;
      }

      isLoadingSlots = true;
      renderCalendar();
      renderTimes();

      var startDate = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
      var endDate = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 0);

      if (startDate < todayStart) {
        startDate = new Date(todayStart);
      }
      if (endDate > maxAvailabilityDate) {
        endDate = new Date(maxAvailabilityDate);
      }

      var startStr = formatDateKey(startDate);
      var endStr = formatDateKey(endDate);

      var url = VM_AJAX_URL + '?action=' + vmhbAction('slots', 'vm_amelia_get_slots')
        + '&serviceId=' + selectedService.id
        + '&date=' + startStr
        + '&endDate=' + endStr
        + '&duration=' + (selectedService.duration || 0)
        + '&_vmhbTs=' + Date.now();

      if (doctorMode === 'manual' && selectedDoctor) {
        url += '&providerId=' + selectedDoctor.id;
      }

      console.log('[VM Booking] Loading slots URL:', url);
      console.log('[VM Booking] Service:', selectedService.id, selectedService.type, 'Duration:', selectedService.duration);
      if (selectedDoctor) console.log('[VM Booking] Doctor:', selectedDoctor.id, selectedDoctor.name);

      var _slotsStart = Date.now();
      var requestId = ++activeSlotsRequestId;
      var fetchOptions = { cache: 'no-store' };

      if (supportsAbortController) {
        if (slotsAbortController) {
          slotsAbortController.abort();
        }
        slotsAbortController = new AbortController();
        fetchOptions.signal = slotsAbortController.signal;
      }

      fetch(url, fetchOptions)
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (requestId !== activeSlotsRequestId) {
            return;
          }

          var elapsed = ((Date.now() - _slotsStart) / 1000).toFixed(2);
          isLoadingSlots = false;
          console.log('[VM Booking] Slots API response (' + elapsed + 's):', JSON.stringify(json).substring(0, 800));

          if (json.success && json.data) {
            applySlotsPayload(json.data);
            if (doctorMode !== 'manual' && selectedDate && selectedTime) {
              syncAutoDoctorForSelectedTime();
              updateIntakeSummary();
            }
            var freeCount = Object.keys(slotsData).length;
            var occCount = Object.keys(occupiedData).length;
            console.log('[VM Booking] Free dates:', freeCount, '| Occupied dates:', occCount);
            if (Object.keys(providerMapData).length > 0) {
              console.log('[VM Booking] Auto mode: providerMap received for', Object.keys(providerMapData).length, 'dates');
            }
            if (freeCount > 0) {
              var firstKey = Object.keys(slotsData)[0];
              console.log('[VM Booking] Sample free:', firstKey, '→', slotsData[firstKey]);
            }
          } else {
            applySlotsPayload(null);
            console.warn('[VM Booking] No slots in response. success:', json.success, 'data:', json.data);
          }

          renderCalendar();
          renderTimes();
        })
        .catch(function (err) {
          if (supportsAbortController && err && err.name === 'AbortError') {
            return;
          }

          if (requestId !== activeSlotsRequestId) {
            return;
          }

          isLoadingSlots = false;
          applySlotsPayload(null);
          renderCalendar();
          renderTimes();
          console.error('[VM Booking] Error loading slots:', err);
        });
    }

    // ====== INICIALIZACIÓN ======
    initializeDefaults();
    renderWeekdays();
    renderCalendar();
    renderTimes();
    setDoctorMode('auto');
    updateSpecialtyValue();
    updateDateValue();
    updateIntakeSummary();

    // Cargar datos reales de Amelia
    loadCatalog();
  });
