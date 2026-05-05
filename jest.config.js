module.exports = {
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/client/tests/**/*.test.{js,jsx}'],
  transform: {
    '^.+\\.[jt]sx?$': 'babel-jest',
  },
};
