<?php
/**
 * Tarjetas de Paquetes
 */
?>
<style>
/* =================================================================== */
/* TARJETAS DE PAQUETES CSS */
/* =================================================================== */

.vm-package-card {
    background: #fff;
    border: 3px solid #05038C;
    border-radius: 30px;
    padding: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    overflow: hidden;
}

.vm-package-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(5, 3, 140, 0.2);
}

.vm-package-header {
    background: linear-gradient(135deg, #05038C 0%, #1a1694 100%);
    padding: 2rem 1.5rem 1.5rem;
    text-align: center;
    position: relative;
}

.vm-package-title {
    font-family: "dm-sans", sans-serif;
    font-size: 2rem;
    font-weight: 600;
    color: #fff;
    margin: 0 0 1rem 0;
    line-height: 1.2;
}

.vm-package-badge {
    display: inline-block;
    background: #D7A9E3;
    color: #05038C;
    font-family: "ibm-plex-sans", sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 0.4rem 1.2rem;
    border-radius: 20px;
    letter-spacing: 0.05em;
}

.vm-package-body {
    padding: 2rem 1.5rem;
    text-align: center;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.vm-package-description {
    font-family: "ibm-plex-sans", sans-serif;
    font-size: 1.1rem;
    color: #333;
    margin-bottom: 1.5rem;
    line-height: 1.5;
}

.vm-package-price {
    margin: 1rem 0;
}

.vm-package-price-old {
    display: block;
    font-family: "dm-sans", sans-serif;
    font-size: 1.3rem;
    color: #999;
    text-decoration: line-through;
    margin-bottom: 0.3rem;
}

.vm-package-price-amount {
    display: block;
    font-family: "dm-sans", sans-serif;
    font-size: 3rem;
    font-weight: 700;
    color: #05038C;
    line-height: 1;
}

.vm-package-validity {
    font-family: "ibm-plex-sans", sans-serif;
    font-size: 0.9rem;
    color: #666;
    font-style: italic;
    margin-top: 1rem;
    margin-bottom: 0;
}

.vm-package-footer {
    padding: 0 1.5rem 2rem;
    text-align: center;
}

/* Botones estandarizados dentro de los paquetes */
.vm-package-footer .vm-btn-outline {
    width: 100%;
    display: block;
}

/* Responsive para móviles */
@media only screen and (max-width: 767px) {
    .vm-package-card {
        border-radius: 25px;
        margin-bottom: 1rem;
    }
    
    .vm-package-header {
        padding: 1.5rem 1rem 1rem;
    }
    
    .vm-package-title {
        font-size: 1.8rem;
    }
    
    .vm-package-badge {
        font-size: 0.85rem;
        padding: 0.3rem 1rem;
    }
    
    .vm-package-body {
        padding: 1.5rem 1rem;
    }
    
    .vm-package-description {
        font-size: 1rem;
        margin-bottom: 1rem;
    }
    
    .vm-package-price-old {
        font-size: 1.1rem;
    }
    
    .vm-package-price-amount {
        font-size: 2.5rem;
    }
    
    .vm-package-validity {
        font-size: 0.85rem;
    }
    
    .vm-package-footer {
        padding: 0 1rem 1.5rem;
    }
}

/* Tablets */
@media only screen and (min-width: 768px) and (max-width: 1024px) {
    .vm-package-title {
        font-size: 1.9rem;
    }
    
    .vm-package-price-amount {
        font-size: 2.8rem;
    }
}
</style>

<div id="vm-tab-panel-paquetes" class="vm-home-panel" data-tab-panel="paquetes" role="tabpanel">
    <div class="row justify-content-center">
        <div class="col-lg-12 text-center mb-5">
            <h2 class="blue pt-5 pb-2">Nuestros Paquetes</h2>
            <p class="dmsans">Selecciona el paquete ideal para ti</p>
        </div>
    </div>
    <div class="row justify-content-center g-4">
        <!-- Paquete de Psicología -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Psicología</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Psicología</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$3,200</span>
                <span class="vm-package-price-amount">$2,720</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Nutrición -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Nutrición</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Nutrición</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$2,400</span>
                <span class="vm-package-price-amount">$2,040</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Medicina General -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Medicina General</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Medicina General</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$1,600</span>
                <span class="vm-package-price-amount">$1,360</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Especialidades -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Especialidades</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Especialidad</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$2,400</span>
                <span class="vm-package-price-amount">$2,040</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Sub-Especialidades -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Sub-Especialidades</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Sub-Especialidad</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$3,200</span>
                <span class="vm-package-price-amount">$2,720</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Lactancia Materna -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Lactancia Materna</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Sub-Especialidad</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$2,400</span>
                <span class="vm-package-price-amount">$2,040</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>

        <!-- Paquete de Psiquiatría -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="vm-package-card">
            <div class="vm-package-header">
                <h3 class="vm-package-title">Psiquiatría</h3>
                <div class="vm-package-badge">15% Descuento</div>
            </div>
            <div class="vm-package-body">
                <p class="vm-package-description">4 Consultas de Psiquiatría</p>
                <div class="vm-package-price">
                <span class="vm-package-price-old">$3,600</span>
                <span class="vm-package-price-amount">$3,060</span>
                </div>
                <p class="vm-package-validity">*Válido por 6 meses*</p>
            </div>
            <div class="vm-package-footer">
                <a href="#agendar-consulta-widget-paquetes" class="vm-btn-outline vm-btn-cta-portal">Adquirir Paquete</a>
            </div>
            </div>
        </div>
    </div>
</div>
