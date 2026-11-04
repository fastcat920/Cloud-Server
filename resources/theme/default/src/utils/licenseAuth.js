/* global wasm_bindgen */

/**
 * 授权码验证工具 - Rust WebAssembly版本
 */
import { SITE_CONFIG, SECURITY_CONFIG, LICENSE_CODE } from './baseConfig';

// 授权状态变量
let authVerified = false;
let authResult = null;
let wasmAuthModule = null;
let systemIntegrityToken = null;

// 添加缓存结果与Promise，防止重复请求同一批文件并降低网络开销
let systemFilesCheckPromise = null;
let systemFilesCheckResult = null;

// 防止短时间内多次触发授权验证，复用同一个 Promise
let verifyLicensePromise = null;

// 表示页面是否已经完成加载的标志
let pageLoaded = false;

// 在页面加载完成后设置页面加载标志
if (typeof window !== 'undefined') {
  if (document.readyState === 'complete') {
    pageLoaded = true;
  } else {
    window.addEventListener('load', () => {
      pageLoaded = true;
    });
  }
}

// 安全密钥 - 用于生成和验证令牌
const SECURITY_KEYS = {
  primary: 'e9a1b2c3d4e5f6g7h8i9j0',
  secondary: 'k1l2m3n4o5p6q7r8s9t0u',
  timestamp: new Date().getTime()
};

/**
 * 生成安全令牌
 * @param {Object} data - 要编码到令牌中的数据
 * @returns {string} 加密的令牌
 */
const generateSecurityToken = (data) => {
  try {
    // 添加时间戳和随机值，防止静态分析
    const tokenData = {
      ...data,
      timestamp: SECURITY_KEYS.timestamp,
      random: Math.random().toString(36).substring(2, 15)
    };
    
    // 简单的编码，实际项目中应使用更强的加密
    const jsonStr = JSON.stringify(tokenData);
    const encoded = btoa(jsonStr);
    
    // 添加混淆签名
    const signature = btoa(SECURITY_KEYS.primary + tokenData.random + SECURITY_KEYS.timestamp);
    return `${encoded}.${signature}`;
  } catch (e) {
    return null;
  }
};

/**
 * 验证安全令牌
 * @param {string} token - 要验证的令牌
 * @returns {Object|null} 解码后的数据或null（如果无效）
 */
const verifySecurityToken = (token) => {
  try {
    if (!token || typeof token !== 'string') return null;
    
    const [encoded, signature] = token.split('.');
    if (!encoded || !signature) return null;
    
    // 解码数据
    const jsonStr = atob(encoded);
    const data = JSON.parse(jsonStr);
    
    // 验证时间戳 (令牌不应过期，因为它在当前会话中生成)
    if (!data.timestamp || data.timestamp !== SECURITY_KEYS.timestamp) {
      return null;
    }
    
    // 验证签名
    const expectedSignature = btoa(SECURITY_KEYS.primary + data.random + SECURITY_KEYS.timestamp);
    if (signature !== expectedSignature) {
      return null;
    }
    
    return data;
  } catch (e) {
    return null;
  }
};

/**
 * 检查必要的系统文件是否存在
 * 这个函数用于迷惑破解者
 */
const checkSystemFiles = async () => {
  // 若已有最终结果，直接返回
  if (systemFilesCheckResult !== null) {
    return systemFilesCheckResult;
  }
  // 若检查仍在进行，等待同一个 Promise
  if (systemFilesCheckPromise) {
    return systemFilesCheckPromise;
  }

  // 等待页面完全加载
  if (typeof window !== 'undefined' && !pageLoaded) {
    systemFilesCheckPromise = new Promise(resolve => {
      const checkPageLoaded = () => {
        if (pageLoaded) {
          systemFilesCheckPromise = null; // 重置Promise以执行实际检查
          resolve(checkSystemFiles()); // 递归调用自身执行实际检查
        } else {
          setTimeout(checkPageLoaded, 200); // 每200ms检查一次页面是否加载完成
        }
      };
      checkPageLoaded();
    });
    return systemFilesCheckPromise;
  }

  // 内部执行单次检查的函数
  const performCheck = async () => {
    try {
      const filesToCheck = [
        'iz553Js0.wasm',
        'ztqU1fZF.wasm',
        'c9VkfH5r.wasm',
        'CGmTuZU3.wasm',
        'lERNeE6X.wasm',
        'zWCd7FQl.wasm',
        'GxmC2HVh.wasm',
        'cmYdYPcS.wasm',
        'jcCaStbg.wasm',
        'config_wasm.js'
      ].map(wasmPath);

      const results = await Promise.all(
        filesToCheck.map(async (path) => {
          try {
            const response = await fetch(path, { method: 'HEAD', cache: 'no-store' });
            const exists = response.ok || response.status === 0 || response.type === 'opaque';
            return { path, exists };
          } catch {
            return { path, exists: false };
          }
        })
      );

      const missingFiles = results.filter((r) => !r.exists);

      const fileCheckData = {
        allFilesExist: missingFiles.length === 0,
        fileCount: filesToCheck.length,
        missingCount: missingFiles.length,
        checksum: filesToCheck.length * 37 + missingFiles.length * 13
      };
      systemIntegrityToken = generateSecurityToken(fileCheckData);
      return fileCheckData.allFilesExist;
    } catch {
      systemIntegrityToken = null;
      return false;
    }
  };

  // 带重试的检查逻辑（默认重试一次，间隔 3 秒）
  systemFilesCheckPromise = (async () => {
    const firstTry = await performCheck();
    if (firstTry) {
      systemFilesCheckResult = true;
      return true;
    }
    // 等待 3 秒后再次检查，解决文件尚未完全加载的场景
    await new Promise((res) => setTimeout(res, 3000));
    const secondTry = await performCheck();
    systemFilesCheckResult = secondTry;
    return secondTry;
  })();

  return systemFilesCheckPromise;
};

/**
 * 验证系统完整性
 * @returns {boolean} 系统是否完整
 */
const verifySystemIntegrity = () => {
  // 验证令牌
  const tokenData = verifySecurityToken(systemIntegrityToken);
  if (!tokenData) return false;
  
  // 验证令牌中的数据
  return tokenData.allFilesExist === true && 
         tokenData.checksum === tokenData.fileCount * 37 + tokenData.missingCount * 13;
};

/**
 * 显示WASM文件缺失遮罩层
 */
const showWasmMissingBlocker = () => {
  if (typeof document !== 'undefined') {
    if (document.getElementById('wasm-missing-blocker')) return;
    
    const blocker = document.createElement('div');
    blocker.id = 'wasm-missing-blocker';
    blocker.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(20, 20, 25, 0.95) 0%, rgba(40, 40, 50, 0.95) 100%);
      z-index: 999999;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
      padding: 20px;
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      transition: all 0.3s ease;
    `;
    
    blocker.innerHTML = `
      <div style="
        max-width: 500px;
        background: rgba(30, 30, 35, 0.7);
        border-radius: 16px;
        padding: 40px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.4), 0 3px 10px rgba(0,0,0,0.3);
        border: 1px solid rgba(255,255,255,0.1);
        text-align: center;
        animation: fadeIn 0.5s ease-out;
      ">
        <div style="
          width: 80px;
          height: 80px;
          margin: 0 auto 30px;
          background: linear-gradient(135deg, #ff6b6b 0%, #ff4757 100%);
          border-radius: 50%;
          display: flex;
          justify-content: center;
          align-items: center;
          box-shadow: 0 10px 20px rgba(255,75,87,0.3);
        ">
          <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
        </div>
        <h2 style="
          margin-bottom: 20px;
          color: white;
          font-weight: 600;
          font-size: 24px;
        ">系统文件缺失</h2>
        <p style="
          margin-bottom: 25px;
          color: rgba(255,255,255,0.8);
          font-size: 16px;
          line-height: 1.6;
        ">
          系统检测到关键文件缺失，无法正常运行。
        </p>
        <p style="
          margin-bottom: 30px;
          color: rgba(255,255,255,0.7);
          font-size: 15px;
          line-height: 1.6;
        ">
          请联系网站管理员修复此问题
        </p>
      </div>
      <style>
        @keyframes fadeIn {
          from { opacity: 0; transform: translateY(-20px); }
          to { opacity: 1; transform: translateY(0); }
        }
      </style>
    `;
    
    document.body.appendChild(blocker);
    document.body.style.overflow = 'hidden';
  }
};

/**
 * WebAssembly模块加载器
 */
const loadWasmAuth = async () => {
  // 等待页面完全加载
  if (typeof window !== 'undefined' && !pageLoaded) {
    return new Promise(resolve => {
      const waitForPageLoad = () => {
        if (pageLoaded) {
          resolve(loadWasmAuth()); // 递归调用自身执行实际加载
        } else {
          setTimeout(waitForPageLoad, 200); // 每200ms检查一次页面是否加载完成
        }
      };
      waitForPageLoad();
    });
  }

  // 检查系统文件是否存在
  const systemIntegrityOk = await checkSystemFiles();
  
  // 二次验证系统完整性（防止简单的返回值修改）
  if (!systemIntegrityOk || !verifySystemIntegrity()) {
    // 文件缺失无法授权
    showWasmMissingBlocker();
    return {
      initialize: () => ({ initialized: false, message: '文件缺失' }),
      verifyLicense: () => ({ 
        isAuthorized: false, 
        message: '文件缺失',
        shouldShowBlocker: true
      })
    };
  }

  // 检查是否已经加载过模块
  if (typeof window.__WASM_AUTH_MODULE !== 'undefined') {
    return window.__WASM_AUTH_MODULE;
  }
  
  try {
    // 动态加载 wasm 授权脚本（若尚未加载）
    if (typeof wasm_bindgen === 'undefined') {
      try {
        await loadWasmJsScript();
      } catch (e) {
        // 加载脚本失败或超时
        showWasmMissingBlocker();
        throw new Error('WASM auth JS load failure');
      }
    }

    // wasm_bindgen 函数由 wasm_auth.js (config_wasm.js) 定义
    if (typeof wasm_bindgen === 'undefined') {
      // 仍未定义说明加载失败
      showWasmMissingBlocker();
      throw new Error('system component not found');
    }
    
    // 再次验证系统完整性（多层验证）
    if (!verifySystemIntegrity()) {
      throw new Error('system integrity check failed');
    }
    
    // 初始化WASM模块，传递WASM文件路径
    await wasm_bindgen({ module_or_path: wasmPath('iz553Js0.wasm') });
    // wasm_bindgen函数在初始化后会包含所有导出函数
    const wasmModule = wasm_bindgen;

    // 创建一个包装对象，提供更友好的API
    const wasmAuth = {
      initialize: () => {
        // 再次验证系统完整性
        if (!verifySystemIntegrity()) {
          return { initialized: false, message: '系统完整性验证失败' };
        }
        
        // Rust中的`initialize_wasm`现在不返回任何内容，只执行设置
        wasmModule.initialize_wasm();
        // JS端返回成功状态
        return { initialized: true, message: '系统模块初始化成功' };
      },
      verifyLicense: (licenseCode, siteName, enableLicenseCheck = true) => {
        // 再次验证系统完整性
        if (!verifySystemIntegrity()) {
          return { 
            isAuthorized: false, 
            message: '系统完整性验证失败',
            shouldShowBlocker: true
          };
        }
        
        const params = JSON.stringify({
          licenseCode,
          siteName,
          enableLicenseCheck
        });

        // 导出函数完整性检查
        const REQUIRED_EXPORTS = [
          'initialize_wasm',
          'getForgotpasswdCode',
          'requestSmsCode',
          'apiV1UserLogin',
          'getsiteConfig',
          'updateUserProfile',
          'pingTest',
          'sendEmailCode', // 授权验证入口 其余迷惑作用
          'verify_license_wasm',
          'validate_license'
        ];

        for (const fn of REQUIRED_EXPORTS) {
          if (typeof wasmModule[fn] !== 'function') {
            // 若缺少任意导出函数，判定模块被删改，立即遮罩
            showWasmMissingBlocker();
            return; // 直接中断
          }
        }

        // 调用真实的授权验证接口
        const resultJson = wasmModule.sendEmailCode(params);
        return JSON.parse(resultJson);
      }
    };
    
    // 缓存模块实例
    window.__WASM_AUTH_MODULE = wasmAuth;
    return wasmAuth;
    
  } catch (error) {
    // console.error('系统组件加载失败:', error);
    
    // 如果WASM加载失败，返回一个模拟的模块以避免应用崩溃
    return {
      initialize: () => ({ initialized: false, message: '无法加载系统组件' }),
      verifyLicense: () => ({ 
        isAuthorized: SECURITY_CONFIG.enableLicenseCheck ? false : true, 
        message: SECURITY_CONFIG.enableLicenseCheck ? '系统组件加载失败' : '系统验证已禁用',
        shouldShowBlocker: false
      })
    };
  }
};

/**
 * 加载WASM授权模块
 * @returns {Promise<Object>} WASM授权模块
 */
const loadWasmAuthModule = async () => {
  if (wasmAuthModule) {
    return wasmAuthModule;
  }
  
  // 等待页面完全加载
  if (typeof window !== 'undefined' && !pageLoaded) {
    return new Promise(resolve => {
      const waitForPageLoad = () => {
        if (pageLoaded) {
          resolve(loadWasmAuthModule()); // 递归调用自身执行实际加载
        } else {
          setTimeout(waitForPageLoad, 200); // 每200ms检查一次页面是否加载完成
        }
      };
      waitForPageLoad();
    });
  }
  
  // 加载WASM模块
  wasmAuthModule = await loadWasmAuth();
    
  // 可选：初始化WASM
  wasmAuthModule.initialize();
    
  return wasmAuthModule;
};

/**
 * 验证授权
 * @returns {Promise<object>} 验证结果，包含isAuthorized和message
 */
export const verifyLicense = async () => {
  // 已完成且缓存了结果
  if (authVerified && authResult) {
    return authResult;
  }
  // 若已有进行中的验证，直接复用
  if (verifyLicensePromise) {
    return verifyLicensePromise;
  }

  // 等待页面完全加载
  if (typeof window !== 'undefined' && !pageLoaded) {
    verifyLicensePromise = new Promise(resolve => {
      const waitForPageLoad = () => {
        if (pageLoaded) {
          verifyLicensePromise = null; // 重置Promise以执行实际验证
          resolve(verifyLicense()); // 递归调用自身执行实际验证
        } else {
          setTimeout(waitForPageLoad, 200); // 每200ms检查一次页面是否加载完成
        }
      };
      waitForPageLoad();
    });
    return verifyLicensePromise;
  }

  // 开始新的验证流程
  verifyLicensePromise = (async () => {
    // 如果禁用了授权验证，直接返回成功
    if (!SECURITY_CONFIG.enableLicenseCheck) {
      authResult = {
        isAuthorized: true,
        message: '系统验证已禁用'
      };
      authVerified = true;
      return authResult;
    }

    try {
      const licenseCode = LICENSE_CODE || '';
      const siteName = SITE_CONFIG.siteName || '';
      const wasmAuth = await loadWasmAuthModule();
      const result = wasmAuth.verifyLicense(
        licenseCode,
        siteName,
        SECURITY_CONFIG.enableLicenseCheck
      );
      authResult = result;
      authVerified = true;
      return result;
    } catch {
      const errorResult = {
        isAuthorized: false,
        message: '系统验证过程出错，请联系管理员'
      };
      authResult = errorResult;
      authVerified = true;
      return errorResult;
    } finally {
      // 清空 Promise 引用，后续调用根据 authVerified 决定是否直接返回
      verifyLicensePromise = null;
    }
  })();

  return verifyLicensePromise;
};

/**
 * 应用授权验证（保持与原domainAuth.js接口兼容）
 * @returns {Promise<object>} 验证结果对象
 */
export const applyDomainAuth = async () => {
  // 等待页面完全加载
  if (typeof window !== 'undefined' && !pageLoaded) {
    return new Promise(resolve => {
      const waitForPageLoad = () => {
        if (pageLoaded) {
          resolve(applyDomainAuth()); // 递归调用自身执行实际验证
        } else {
          setTimeout(waitForPageLoad, 200); // 每200ms检查一次页面是否加载完成
        }
      };
      waitForPageLoad();
    });
  }
  
  try {
    // 验证授权
    const result = await verifyLicense();
    
    // 返回保持与原接口兼容的结果
    return {
      isAuthorized: result.isAuthorized,
      apiDomain: result.message
    };
  } catch (error) {
    // console.error('应用授权验证失败:', error);
    return {
      isAuthorized: false,
      apiDomain: '授权验证过程出错'
    };
  }
};

/**
 * ======================  动态加载WASM授权脚本  ======================
 * 为了防止用户在 index.html 中删除 <script> 标签，我们改为在运行时
 * 通过 JS 注入方式加载 /static/modules/core/config_wasm.js。
 * 若加载失败或超时，则立即显示遮罩层并返回降级模块。
 */

// 授权脚本路径 (如需自定义可自行修改)
const WASM_JS_PATH = 'static/modules/core/config_wasm.js';
// 新增：config_wasm.js 文件内容的 SHA-256 校验值（16 进制）——发布时请替换
const WASM_JS_HASH = '1366e5648d96d376a779cc10dff0304a795a37b581d18baa1b833db6767d4645';

// 运行时把十六进制转换为 SRI 需要的 base64 格式
const WASM_JS_INTEGRITY = (() => {
  if (!WASM_JS_HASH) return '';
  try {
    const binary = WASM_JS_HASH.match(/.{2}/g)
      .map((byte) => String.fromCharCode(parseInt(byte, 16)))
      .join('');
    // btoa 只接受 Latin1 字符串
    return 'sha256-' + btoa(binary);
  } catch {
    return '';
  }
})();

// 加载超时时间(ms)
const WASM_JS_TIMEOUT = 5000;

// ======================  WASM 路径与基址 ======================
// 初始默认路径，可在运行时根据实际 script.src 自动纠正
let wasmBasePath = 'static/modules/core/';

// 根据 script.src 解析基路径
const updateWasmBasePath = (scriptSrc) => {
  try {
    // 去掉 query 与文件名，保留末尾斜杠
    const url = new URL(scriptSrc, location.origin);
    // 移除开头斜杠，保持相对路径，兼容 file://
    wasmBasePath = url.pathname.replace(/[^/]+$/g, '');
  } catch (e) {
    // 忽略解析错误，保持默认
  }
};

// 辅助函数：根据文件名拼接完整路径
const wasmPath = (file) => `${wasmBasePath}${file}`;

/**
 * 通过 fetch 获取脚本并进行完整性校验，校验通过后执行 eval。
 * 作为 <script> 注入失败时的兜底方案，可绕过部分篡改/拦截场景。
 */
const fetchAndEvalWasmJs = async () => {
  // 获取脚本源码，禁止缓存
  const res = await fetch(`${WASM_JS_PATH}?t=${Date.now()}`, { cache: 'no-store' });
  if (!res.ok) {
    throw new Error('WASM auth JS fetch error');
  }
  const scriptText = await res.text();

  // ------ 完整性校验 (可选，若浏览器支持 SubtleCrypto 且填写了 HASH) ------
  if (WASM_JS_HASH && typeof crypto !== 'undefined' && crypto.subtle) {
    const buf = new TextEncoder().encode(scriptText);
    const hashBuf = await crypto.subtle.digest('SHA-256', buf);
    const hashHex = Array.from(new Uint8Array(hashBuf))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
    if (hashHex !== WASM_JS_HASH.toLowerCase()) {
      throw new Error('WASM auth JS integrity mismatch');
    }
  }

  // 更新基路径，确保后续 wasmPath 正确
  updateWasmBasePath(WASM_JS_PATH);

  // 通过 (0, eval) 避免在严格模式下影响当前作用域
  (0, eval)(`${scriptText}\n//# sourceURL=${WASM_JS_PATH}`);
};

/**
 * 动态加载授权脚本
 * @returns {Promise<void>}
 */
const loadWasmJsScript = () => {
  return new Promise((resolve, reject) => {
    // 如果已经加载或正在加载
    const existing = document.getElementById('__ez_wasm_auth_js');
    if (existing) {
      updateWasmBasePath(existing.src);
      // 等待下一轮事件循环，确保 onload 已触发
      setTimeout(resolve, 0);
      return;
    }

    const script = document.createElement('script');
    script.id = '__ez_wasm_auth_js';
    script.src = `${WASM_JS_PATH}?t=${Date.now()}`;
    script.async = true;
    // 设置 SRI，浏览器会在下载完成后自动校验摘要
    if (WASM_JS_INTEGRITY) {
      script.integrity = WASM_JS_INTEGRITY;
      script.crossOrigin = 'anonymous';
    }

    // 兜底函数：在 <script> 注入失败时，尝试 fetch+eval 方式加载
    const fallbackLoad = () => {
      fetchAndEvalWasmJs()
        .then(resolve)
        .catch(reject);
    };

    const timer = setTimeout(() => {
      cleanup();
      fallbackLoad();
    }, WASM_JS_TIMEOUT);

    script.onload = () => {
      updateWasmBasePath(script.src);
      cleanup();
      resolve();
    };

    script.onerror = () => {
      cleanup();
      fallbackLoad();
    };

    function cleanup() {
      clearTimeout(timer);
      script.onload = null;
      script.onerror = null;
    }

    document.head.appendChild(script);
  });
}; 