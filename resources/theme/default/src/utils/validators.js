/**
 * 表单验证工具
 */

/**
 * 验证邮箱格式
 * @param {string} email - 邮箱地址
 * @returns {boolean} 是否有效
 */
export function isValidEmail(email) {
  if (!email) return false;
  
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}

/**
 * 简化的邮箱验证函数
 * @param {string} email - 邮箱地址
 * @returns {boolean} 是否有效
 */
export const validateEmail = (email) => {
  return isValidEmail(email);
};

/**
 * 验证密码强度
 * @param {string} password - 密码
 * @returns {object} 验证结果
 */
export const validatePassword = (password) => {
  const result = {
    valid: false,
    message: ''
  };
  
  if (!password) {
    result.message = '请输入密码';
    return result;
  }
  
  if (password.length < 8) {
    result.message = '密码长度至少为8个字符';
    return result;
  }
  
  // 检查是否包含数字
  const hasNumber = /\d/.test(password);
  // 检查是否包含小写字母
  const hasLowercase = /[a-z]/.test(password);
  // 检查是否包含大写字母
  const hasUppercase = /[A-Z]/.test(password);
  // 检查是否包含特殊字符
  const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
  
  const strength = [hasNumber, hasLowercase, hasUppercase, hasSpecial].filter(Boolean).length;
  
  if (strength < 3) {
    result.message = '密码强度不足，请包含数字、大小写字母和特殊字符';
    return result;
  }
  
  result.valid = true;
  return result;
};

/**
 * 验证两个密码是否匹配
 * @param {string} password - 密码
 * @param {string} confirmPassword - 确认密码
 * @returns {boolean} 是否匹配
 */
export function passwordsMatch(password, confirmPassword) {
  if (!password || !confirmPassword) return false;
  return password === confirmPassword;
}

/**
 * 验证必填字段
 * @param {string} value - 字段值
 * @param {string} fieldName - 字段名称
 * @returns {object} 验证结果
 */
export const validateRequiredWithMessage = (value, fieldName) => {
  const result = {
    valid: false,
    message: ''
  };
  
  if (!value || (typeof value === 'string' && value.trim() === '')) {
    result.message = `${fieldName}不能为空`;
    return result;
  }
  
  result.valid = true;
  return result;
};

/**
 * 简化的必填验证函数
 * @param {string} value - 字段值
 * @returns {boolean} 是否有效
 */
export const validateRequired = (value) => {
  return !!value && (typeof value !== 'string' || value.trim() !== '');
};

/**
 * 密码强度验证
 * @param {string} password - 密码
 * @returns {boolean} 是否足够强
 */
export function isStrongPassword(password) {
  if (!password) return false;
  
  // 至少8个字符，包含数字和字母
  const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;
  return passwordRegex.test(password);
} 